const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  await page.goto('http://localhost/login');
  await page.fill('input[type="email"]', 'crm@ogami.test');
  await page.fill('input[type="password"]', 'password');
  await page.click('button[type="submit"]');
  
  await page.waitForTimeout(5000); // Wait to see if it's stuck loading
  console.log("URL after login:", page.url());
  
  const content = await page.content();
  if (content.includes('Loading')) {
      console.log('Stuck in loading state');
  }
  await browser.close();
})();
