const { chromium } = require('/home/ec2-user/kwatog/spa/node_modules/playwright');
const fs = require('node:fs');
const fixture = JSON.parse(fs.readFileSync('/home/ec2-user/kwatog/docs/testing/evidence/quality-process-ui-implementation-20261006/fixture.json', 'utf8'));
(async () => {
 const browser = await chromium.launch({headless:true});
 try {
 const context = await browser.newContext({baseURL:'http://localhost:18080',viewport:{width:1440,height:1000}});
 const headers={Origin:'http://localhost:18080',Referer:'http://localhost:18080/',Accept:'application/json'};
 await context.request.get('/sanctum/csrf-cookie',{headers});
 const token=(await context.cookies()).find(c=>c.name==='XSRF-TOKEN');
 const login=await context.request.post('/api/v1/auth/sign-in',{headers:{...headers,'X-XSRF-TOKEN':decodeURIComponent(token.value)},data:{email:fixture.accounts.maker,password:'password'}});
 if(login.status()!==200)throw new Error(await login.text());
 const page=await context.newPage(); const results=[];
 const routes=[['list','/quality/inspections'],['create','/quality/inspections/new'],['failed',`/quality/inspections/${fixture.inspections.size_fail.outgoing}`],['incoming',`/quality/inspections/${fixture.inspections.pass.incoming}`]];
 for (const [name,route] of routes) {
 await page.goto(route); await page.getByRole('heading',{level:1}).waitFor(); await page.waitForTimeout(1800);
 for(const width of [1440,390]) {
 await page.setViewportSize({width,height:1000}); await page.waitForTimeout(150);
 const data=await page.evaluate(()=>({scrollWidth:document.documentElement.scrollWidth,viewport:window.innerWidth,checklistY:document.querySelector('[aria-label="Inspection checklist"]')?.getBoundingClientRect().top,body:document.querySelector('main')?.innerText,smallButtons:[...document.querySelectorAll('main button')].map(e=>({name:e.getAttribute('aria-label')||e.textContent,rect:e.getBoundingClientRect()})).filter(b=>b.rect.width>0&&(b.rect.width<44||b.rect.height<44)).map(b=>({name:b.name,width:b.rect.width,height:b.rect.height}))}));
 results.push({name,width,...data});
 await page.screenshot({path:`/home/ec2-user/kwatog/docs/testing/evidence/quality-process-ui-implementation-20261006/${name}-${width}.png`,fullPage:true});
 }
 await page.setViewportSize({width:1440,height:1000});
 }
 fs.writeFileSync('/home/ec2-user/kwatog/docs/testing/evidence/quality-process-ui-implementation-20261006/browser.json',JSON.stringify(results,null,2));
 console.log('Read-only captures complete: list, create, failed outgoing, passed incoming; desktop/mobile.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
