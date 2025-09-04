#!/usr/bin/env python3
"""
Selenium script to load all properties by clicking Load More button
Save this as selenium_loader.py in your project directory
"""

import sys
import time
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.common.exceptions import TimeoutException, NoSuchElementException

def load_all_properties(url):
    # Setup Chrome options
    chrome_options = Options()
    chrome_options.add_argument("--headless")  # Run in background
    chrome_options.add_argument("--no-sandbox")
    chrome_options.add_argument("--disable-dev-shm-usage")
    chrome_options.add_argument("--disable-gpu")
    chrome_options.add_argument("--window-size=1920,1080")
    chrome_options.add_argument("--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36")
    
    driver = None
    try:
        # Initialize the driver
        driver = webdriver.Chrome(options=chrome_options)
        driver.get(url)
        
        # Wait for initial page load
        wait = WebDriverWait(driver, 20)
        wait.until(EC.presence_of_element_located((By.CLASS_NAME, "framer-158w17z")))
        
        print("✅ Page loaded successfully", file=sys.stderr)
        
        # Count initial properties
        initial_properties = len(driver.find_elements(By.CSS_SELECTOR, ".framer-158w17z .framer-1j2oag6-container"))
        print(f"📊 Initial properties loaded: {initial_properties}", file=sys.stderr)
        
        max_clicks = 50  # Prevent infinite loop
        clicks = 0
        
        while clicks < max_clicks:
            try:
                # Look for Load More button - try multiple possible selectors
                load_more_selectors = [
                    ".framer-phl5i9-container .framer-216ND",  # From your HTML
                    "[data-framer-name='Default'][data-highlight='true']",
                    "button:contains('Load More')",
                    ".framer-216ND",
                    "*[role='button']:contains('Load More')"
                ]
                
                load_more_button = None
                for selector in load_more_selectors:
                    try:
                        if ":contains(" in selector:
                            # Handle contains selector differently
                            elements = driver.find_elements(By.XPATH, f"//*[contains(text(), 'Load More') or contains(text(), 'LOAD MORE')]")
                            if elements:
                                load_more_button = elements[0]
                                break
                        else:
                            load_more_button = driver.find_element(By.CSS_SELECTOR, selector)
                            break
                    except NoSuchElementException:
                        continue
                
                if not load_more_button:
                    print("🛑 No Load More button found - all properties loaded", file=sys.stderr)
                    break
                
                # Check if button is visible and clickable
                if not load_more_button.is_displayed():
                    print("🛑 Load More button not visible - all properties loaded", file=sys.stderr)
                    break
                
                # Scroll to button
                driver.execute_script("arguments[0].scrollIntoView({behavior: 'smooth', block: 'center'});", load_more_button)
                time.sleep(2)
                
                # Click the Load More button
                try:
                    driver.execute_script("arguments[0].click();", load_more_button)
                except Exception:
                    load_more_button.click()
                
                clicks += 1
                print(f"🔄 Clicked Load More button (click #{clicks})", file=sys.stderr)
                
                # Wait for new properties to load
                time.sleep(3)
                
                # Check if new properties were loaded
                current_properties = len(driver.find_elements(By.CSS_SELECTOR, ".framer-158w17z .framer-1j2oag6-container"))
                print(f"📊 Properties after click #{clicks}: {current_properties}", file=sys.stderr)
                
                # If no new properties loaded, we're done
                if current_properties == initial_properties and clicks > 1:
                    print("🛑 No new properties loaded - assuming we've reached the end", file=sys.stderr)
                    break
                
                initial_properties = current_properties
                
            except TimeoutException:
                print("⏰ Timeout waiting for Load More button", file=sys.stderr)
                break
            except Exception as e:
                print(f"❌ Error clicking Load More: {str(e)}", file=sys.stderr)
                break
        
        # Final count
        final_properties = len(driver.find_elements(By.CSS_SELECTOR, ".framer-158w17z .framer-1j2oag6-container"))
        print(f"✅ Final properties loaded: {final_properties}", file=sys.stderr)
        
        # Get the full page source
        page_source = driver.page_source
        return page_source
        
    except Exception as e:
        print(f"❌ Error in Selenium script: {str(e)}", file=sys.stderr)
        return None
    finally:
        if driver:
            driver.quit()

if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: python3 selenium_loader.py <url>", file=sys.stderr)
        sys.exit(1)
    
    url = sys.argv[1]
    print(f"🚀 Loading URL: {url}", file=sys.stderr)
    
    html = load_all_properties(url)
    
    if html:
        print(html)  # Output HTML to stdout for PHP to capture
    else:
        print("Failed to load page", file=sys.stderr)
        sys.exit(1)