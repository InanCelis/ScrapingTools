const puppeteer = require('puppeteer');
const fs = require('fs');

async function scrapeUrl(url, outputFile) {
    let browser;
    
    try {
        console.log(`Starting to scrape: ${url}`);
        
        // Launch browser with more options
        browser = await puppeteer.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--no-zygote',
                '--disable-gpu',
                '--disable-web-security',
                '--disable-features=VizDisplayCompositor',
                '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ],
            timeout: 30000
        });

        const page = await browser.newPage();
        
        // Set viewport and user agent
        await page.setViewport({ width: 1920, height: 1080 });
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        
        // Set extra headers
        await page.setExtraHTTPHeaders({
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.5',
            'Accept-Encoding': 'gzip, deflate',
            'Connection': 'keep-alive',
            'Upgrade-Insecure-Requests': '1',
        });

        // Navigate with multiple timeout strategies
        console.log('Navigating to URL...');
        
        try {
            // First attempt: Normal navigation
            await page.goto(url, { 
                waitUntil: 'networkidle2', 
                timeout: 45000 
            });
        } catch (timeoutError) {
            console.log('First attempt timed out, trying with domcontentloaded...');
            try {
                await page.goto(url, { 
                    waitUntil: 'domcontentloaded', 
                    timeout: 30000 
                });
            } catch (secondTimeout) {
                console.log('Second attempt timed out, trying basic load...');
                await page.goto(url, { 
                    waitUntil: 'load', 
                    timeout: 20000 
                });
            }
        }

        console.log('Page loaded, waiting for content...');
        
        // Wait for specific elements or just give time for JS to render
        try {
            // Wait for property items to load
            await page.waitForSelector('app-property-item', { timeout: 15000 });
            console.log('Property items found');
        } catch (e) {
            console.log('No app-property-item found, waiting for general content...');
            // Just wait a bit for any JS to execute
            await page.waitForTimeout(5000);
        }

        // Additional wait for any lazy loading
        await page.waitForTimeout(3000);

        console.log('Getting page content...');
        const content = await page.content();
        
        console.log(`Content length: ${content.length} characters`);
        
        // Save the content
        fs.writeFileSync(outputFile, content);
        console.log(`Content saved to: ${outputFile}`);
        
    } catch (error) {
        console.error('Error scraping URL:', error.message);
        
        // Try a simpler approach if everything fails
        if (browser) {
            try {
                const page = await browser.newPage();
                await page.goto(url, { waitUntil: 'load', timeout: 10000 });
                const content = await page.content();
                fs.writeFileSync(outputFile, content);
                console.log('Fallback method succeeded');
            } catch (fallbackError) {
                console.error('Fallback also failed:', fallbackError.message);
                throw error;
            }
        } else {
            throw error;
        }
    } finally {
        if (browser) {
            await browser.close();
        }
    }
}

// Get command line arguments
const args = process.argv.slice(2);
if (args.length !== 2) {
    console.error('Usage: node puppeteer-scraper.js <url> <output-file>');
    process.exit(1);
}

const [url, outputFile] = args;

// Run the scraper
scrapeUrl(url, outputFile)
    .then(() => {
        console.log('Scraping completed successfully');
        process.exit(0);
    })
    .catch((error) => {
        console.error('Scraping failed:', error.message);
        console.error('Stack trace:', error.stack);
        process.exit(1);
    });