const { chromium, expect } = require('/home/ec2-user/kwatog/spa/node_modules/@playwright/test');
(async () => {
 const browser = await chromium.launch({headless:true});
 try {
  const context = await browser.newContext({baseURL:'https://47.129.228.44',ignoreHTTPSErrors:true,viewport:{width:1440,height:1000}});
  const headers={Origin:'https://47.129.228.44',Referer:'https://47.129.228.44/',Accept:'application/json'};
  await context.request.get('/sanctum/csrf-cookie',{headers});
  const csrf=(await context.cookies()).find(c=>c.name==='XSRF-TOKEN');
  expect((await context.request.post('/api/v1/auth/sign-in',{headers:{...headers,'X-XSRF-TOKEN':decodeURIComponent(csrf.value)},data:{email:'production@ogami.test',password:'password'}})).status()).toBe(200);
  const list=await context.request.get('/api/v1/production/work-orders?status=confirmed&per_page=5',{headers});expect(list.status()).toBe(200);
  const candidate=(await list.json()).data[0];
  const woId=candidate?.id||'readonly-browser-fixture';const apiPath='/api/v1/production/work-orders/'+woId;
  const before=candidate?(await (await context.request.get(apiPath,{headers})).json()).data:{
    id:woId,wo_number:'WO-202610-0014',batch_number:null,material_lot_references:[],work_order_class:'standard',product:{id:'product',part_number:'BU-001',name:'Windshield Wiper Bushing'},parent:null,children:[],sales_order:null,machine:null,mold:null,quantity_target:100,quantity_produced:0,quantity_good:0,quantity_rejected:0,progress_percentage:0,scrap_rate:'0.00',planned_start:'2026-10-08T08:00:00Z',planned_end:'2026-10-09T08:00:00Z',actual_start:null,actual_end:null,status:'confirmed',status_label:'Confirmed',next_statuses:[],pause_reason:null,priority:50,creator:null,materials:[],material_cost_summary:null,outputs:[],inspections:[],created_at:'2026-10-08T08:00:00Z',updated_at:'2026-10-08T08:00:00Z'
  };
  const actual={ready:true,needs_reschedule:false,blockers:[],presence:[],expected_crew_id:'test-crew',schedule:null};
  console.log(candidate?'Read-only existing WO fixture':'No confirmed live WO available; isolated browser fixture used.');
  const page=await context.newPage(), errors=[], intercepted=[];let checks=0,changed=false,running=false;
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/api/**',route=>{
    if(route.request().method()==='GET')return route.continue();
    if(route.request().url().includes('/production/work-orders/'))intercepted.push({url:route.request().url(),body:route.request().postDataJSON()});
    if(route.request().url().endsWith('/extend-run'))return route.fulfill({json:{data:{...before,status:'in_progress'}}});
    return route.abort('blockedbyclient');
  });
  await page.route('**/api/v1/production/work-orders/*/chain',r=>r.fulfill({json:{data:[]}}));
  await page.route('**/api/v1/production/work-orders/*/operations',r=>r.fulfill({json:{data:[]}}));
  await page.route('**/api/v1/production/work-orders/*/crew',r=>r.fulfill({json:{data:{current:null,history:[],minimum_workers:1,maximum_workers:4,can_assign:false}}}));
  await page.clock.install();
  await page.route('**/execution-readiness',route=>{
    checks++;
    return route.fulfill({json:{data:{...actual,ready:true,needs_reschedule:false,blockers:[],can_review_slot:true,can_approve_late_production:true,
      schedule:actual.schedule||{id:'slot',updated_at:'2026-10-08T08:00:00Z',scheduled_start:'2026-10-08T08:00:00Z',scheduled_end:'2026-10-08T10:00:00Z'},
      presence:running?[]:[{employee_id:'worker',name:'Test operator',state:'unknown',work_date:changed?'2026-10-09':'2026-10-08',reason:null}],
      expected_crew_id:actual.expected_crew_id||'test-crew',start_mode:running?'extend':'late',requires_late_start_acknowledgement:!running,requires_late_production_approval:running,
      estimated_finish:new Date(Date.now()+3600000).toISOString(),execution_end:new Date(Date.now()+4500000).toISOString()}}});
  });
  await page.route('**/api/v1/production/work-orders/'+woId,route=>route.fulfill({json:{data:{...before,status:'confirmed',status_label:'Confirmed'}}}));
  await page.goto('/production/work-orders/'+woId);
  await page.getByRole('button',{name:'Start',exact:true}).click();
  const dialog=page.getByRole('dialog');
  const late=dialog.getByRole('checkbox',{name:/I approve this late start/});
  const physical=dialog.getByRole('checkbox',{name:/Confirm physically present/});
  await late.check();await physical.check();
  const count=checks;await page.clock.fastForward(31000);await expect.poll(()=>checks).toBeGreaterThan(count);
  await expect(late).toBeChecked();await expect(physical).toBeChecked();
  changed=true;const count2=checks;await page.clock.fastForward(31000);await expect.poll(()=>checks).toBeGreaterThan(count2);
  await expect(late).not.toBeChecked();await expect(physical).not.toBeChecked();
  const targets=await dialog.getByRole('button').evaluateAll(buttons=>buttons.map(b=>({label:b.textContent.trim()||b.getAttribute('aria-label'),height:b.getBoundingClientRect().height,width:b.getBoundingClientRect().width})));
  expect(targets.every(t=>t.height>=44&&t.width>=44)).toBe(true);
  console.log('Stable confirmations survive unchanged polling; changed attendance clears approvals; touch targets',JSON.stringify(targets));
  await dialog.getByRole('button',{name:'Close',exact:true}).click();
  running=true;
  await page.unroute('**/api/v1/production/work-orders/'+woId);
  await page.route('**/api/v1/production/work-orders/'+woId,route=>route.fulfill({json:{data:{...before,status:'in_progress',status_label:'In Progress',process_quality_blocker:null,execution_history:[{id:'event',summary:'Running production extended for WO-202610-0014.',created_at:'2026-10-08T10:00:00Z',actor:{name:'Test manager'},detail:{approved_start:'2026-10-08T08:00:00Z',approved_end:'2026-10-08T10:00:00Z',scheduled_start:'2026-10-08T10:00:00Z',scheduled_end:'2026-10-08T11:00:00Z',reason:'Remaining run reviewed.',late_production_reason:'Customer notified.'}}]}}}));
  await page.reload();
  await page.getByRole('button',{name:'Extend run',exact:true}).click();
  const extension=dialog.getByRole('button',{name:'Approve extension',exact:true});
  await expect(extension).toBeDisabled();
  await dialog.getByRole('checkbox',{name:'I approve production past the deadline.'}).check();
  await dialog.getByRole('textbox',{name:'Delivery impact / reason'}).fill('Customer notified; remaining production reviewed.');
  await expect(extension).toBeEnabled();
  await page.screenshot({path:'/tmp/wo-extension-desktop.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});
  await expect(extension).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await page.screenshot({path:'/tmp/wo-extension-mobile.png',fullPage:true});
  await extension.click();await expect(dialog).not.toBeVisible();
  expect(intercepted).toHaveLength(1);expect(intercepted[0].url.endsWith('/extend-run')).toBe(true);
  expect(intercepted[0].body.late_production_authorized).toBe(true);expect(intercepted[0].body.late_production_reason).toContain('Customer notified');
  await page.getByText('Timing details',{exact:true}).click();
  await expect(page.getByText('Delivery impact: Customer notified.')).toBeVisible();
  expect(errors).toEqual([]);
  const after=candidate?(await (await context.request.get(apiPath,{headers})).json()).data:before;
  for(const key of ['status','actual_start','quantity_produced','quantity_good','batch_number'])expect(after[key]).toEqual(before[key]);
  console.log('Extension requires separate deadline approval; intercepted payload valid; history shows delivery impact; mobile fits. No live WO mutations or page errors.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
