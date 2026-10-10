const {chromium,expect}=require('/home/ec2-user/kwatog/spa/node_modules/@playwright/test');
const fs=require('node:fs');
const fixture=JSON.parse(fs.readFileSync('/tmp/spec-required-fixture.json','utf8'));
(async()=>{const browser=await chromium.launch({headless:true});try{
const context=await browser.newContext({baseURL:'http://localhost:18080',viewport:{width:1440,height:1000}});
const headers={Origin:'http://localhost:18080',Referer:'http://localhost:18080/',Accept:'application/json'};
await context.request.get('/sanctum/csrf-cookie',{headers});let token=(await context.cookies()).find(c=>c.name==='XSRF-TOKEN');
const login=await context.request.post('/api/v1/auth/sign-in',{headers:{...headers,'X-XSRF-TOKEN':decodeURIComponent(token.value)},data:{email:fixture.email,password:'password'}});expect(login.status()).toBe(200);
const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(`/quality/inspection-specs/${fixture.product}`);
await expect(page.getByRole('heading',{name:'Size',level:3})).toBeVisible();await expect(page.getByRole('button',{name:'Add check',exact:true})).toHaveCount(0);
await page.getByRole('button',{name:'Create spec',exact:true}).click();
for(const message of ['Choose a measurement unit.','Enter the measurement target.','Enter the lowest allowed value.','Enter the highest allowed value.'])await expect(page.getByText(message,{exact:true})).toBeVisible();
for(const width of [1440,390]){await page.setViewportSize({width,height:1000});await page.screenshot({path:`/tmp/spec-required-${width}.png`,fullPage:true});const dimensions=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,viewport:innerWidth}));expect(dimensions.scroll).toBe(dimensions.viewport);}
await page.getByRole('button',{name:'Edit Product identity',exact:true}).click();await expect(page.getByRole('combobox',{name:'What to check 1'}).locator('option[value="Appearance"]')).toBeDisabled();
await page.getByRole('button',{name:'Remove check 5',exact:true}).click();await page.getByRole('button',{name:'Add check',exact:true}).click();await page.getByRole('combobox',{name:'What to check 5'}).selectOption('Packaging');await expect(page.getByRole('button',{name:'Add check',exact:true})).toHaveCount(0);
token=(await context.cookies()).find(c=>c.name==='XSRF-TOKEN');const rejected=await context.request.post('/api/v1/quality/inspection-specs',{headers:{...headers,'X-XSRF-TOKEN':decodeURIComponent(token.value)},data:{product_id:fixture.product,capture_method:'checklist',items:[{parameter_name:'Size',parameter_type:'dimensional',acceptance_criterion:'Follow approved drawing.'}]}});expect(rejected.status()).toBe(422);const validation=await rejected.json();for(const field of ['unit_of_measure','nominal_value','tolerance_min','tolerance_max'])expect(validation.errors[`items.0.${field}`]).toBeTruthy();
await page.getByRole('combobox',{name:'Unit 3',exact:true}).selectOption('mm');for(const [label,value] of [['Target 3','10'],['Lowest allowed 3','9.9'],['Highest allowed 3','10.1']])await page.getByRole('textbox',{name:label,exact:true}).fill(value);
const saved=page.waitForResponse(r=>r.url().endsWith('/api/v1/quality/inspection-specs')&&r.request().method()==='POST');await page.getByRole('button',{name:'Create spec',exact:true}).click();const response=await saved;expect(response.status()).toBe(200);const record=(await response.json()).data;expect(record.items).toHaveLength(5);expect(record.items.find(c=>c.parameter_name==='Size').unit_of_measure).toBe('mm');
expect(errors).toEqual([]);console.log('Headless real API passed: incomplete size rejected by form and API, duplicates disabled, Add check hides/restores, valid five-check spec saves; desktop/mobile no overflow. Audit database only.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
