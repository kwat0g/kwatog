const { chromium }=require('/home/ec2-user/kwatog/spa/node_modules/playwright');
const fs=require('node:fs');
const fixture=JSON.parse(fs.readFileSync('/home/ec2-user/kwatog/docs/testing/evidence/quality-process-ui-implementation-20261006/fixture.json','utf8'));
(async()=>{const browser=await chromium.launch({headless:true});try{
const context=await browser.newContext({baseURL:'http://localhost:18080'});
const headers={Origin:'http://localhost:18080',Referer:'http://localhost:18080/',Accept:'application/json'};
await context.request.get('/sanctum/csrf-cookie',{headers});const token=(await context.cookies()).find(x=>x.name==='XSRF-TOKEN');
const login=await context.request.post('/api/v1/auth/sign-in',{headers:{...headers,'X-XSRF-TOKEN':decodeURIComponent(token.value)},data:{email:fixture.accounts.maker,password:'password'}});if(login.status()!==200)throw new Error(await login.text());
const get=async url=>{const r=await context.request.get(url,{headers});if(r.status()!==200)throw new Error(await r.text());return(await r.json()).data};
const failed=await get(`/api/v1/quality/inspections/${fixture.inspections.size_fail.outgoing}`);
const pareto=await get(`/api/v1/quality/analytics/defect-pareto?product_id=${failed.product.id}&from=2026-10-01&to=2026-10-10`);
const ncrs=await get(`/api/v1/quality/ncrs?inspection_id=${failed.id}`);
fs.writeFileSync('/home/ec2-user/kwatog/docs/testing/evidence/quality-process-ui-implementation-20261006/analytics.json',JSON.stringify({failedChecks:failed.measurements.filter(x=>x.item_result==='failed').map(x=>({name:x.parameter_name,is_pass:x.is_pass})),pareto,ncrCount:ncrs.length},null,2));
console.log(fs.readFileSync('/home/ec2-user/kwatog/docs/testing/evidence/quality-process-ui-implementation-20261006/analytics.json','utf8'));
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
