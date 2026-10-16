const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const https = require('node:https');
const assert = require('node:assert/strict');
const {spawn, execFileSync} = require('node:child_process');
const {chromium} = require(process.env.PRINTER_PLAYWRIGHT_MODULE || '@playwright/test');
const source = path.resolve(__dirname, '..');
const root = fs.mkdtempSync(path.join(os.tmpdir(), 'oop-printer-independent-'));
const artifacts = path.resolve(process.env.PRINTER_TEST_ARTIFACTS || path.join(source, '../../../.local/printer-tests'));
fs.mkdirSync(artifacts, {recursive:true});
fs.mkdirSync(path.join(root, 'public'));
fs.mkdirSync(path.join(root, 'protected/printer-sessions'), {recursive:true});
fs.cpSync(path.join(source, 'app'), path.join(root, 'protected/app'), {recursive:true});
fs.copyFileSync(path.join(source, 'public/index.php'), path.join(root, 'public/index.php'));
const hash = execFileSync('php', ['-r', 'echo password_hash("local-printer-only", PASSWORD_DEFAULT);'], {encoding:'utf8'});
fs.writeFileSync(path.join(root, 'protected/printer-password.hash'), hash + '\n', {mode:0o600});
fs.writeFileSync(path.join(root, 'protected/printer-device-token'), 'd'.repeat(64), {mode:0o600});
fs.writeFileSync(path.join(root, 'protected/printer-relay.json'), JSON.stringify({version:1,device:{lastSeen:0,version:'',busy:false},jobs:[]}), {mode:0o600});
const router = path.join(root, 'router.php');
fs.writeFileSync(router, '<?php require ' + JSON.stringify(path.join(root, 'public/index.php')) + ';');
const php = spawn('php', ['-S', '127.0.0.1:8894', '-t', path.join(root,'public'), router], {env:{...process.env,OOP_PRINTER_SITE_ROOT:root},stdio:['ignore','pipe','pipe']});
let phpLogs = ''; php.stderr.on('data', d => phpLogs += d.toString());
const origin = 'https://printer.objectsonpurpose.com:8896';
let browser, proxy;
(async()=>{
  try {
    for(let attempt=0;attempt<30;attempt++) {
      try { await fetch('http://127.0.0.1:8894/'); break; } catch(error) {if(attempt===29) throw error; await new Promise(r=>setTimeout(r,100));}
    }
    execFileSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',path.join(root,'test.key'),'-out',path.join(root,'test.crt'),'-days','1','-subj','/CN=printer.objectsonpurpose.com'],{stdio:'ignore'});
    proxy = https.createServer({key:fs.readFileSync(path.join(root,'test.key')),cert:fs.readFileSync(path.join(root,'test.crt'))},(req,res)=>{
      const upstream=http.request({host:'127.0.0.1',port:8894,path:req.url,method:req.method,headers:req.headers},reply=>{res.writeHead(reply.statusCode,reply.headers);reply.pipe(res);});
      upstream.on('error',()=>{res.writeHead(502);res.end();});req.pipe(upstream);
    });
    await new Promise(resolve=>proxy.listen(8896,'127.0.0.1',resolve));
    browser=await chromium.launch({headless:true,...(process.env.PRINTER_TEST_BROWSER?{executablePath:process.env.PRINTER_TEST_BROWSER}:{}),args:['--host-resolver-rules=MAP printer.objectsonpurpose.com 127.0.0.1','--no-proxy-server']});
    // Only the temporary, self-signed local fixture bypasses TLS validation.
    const context=await browser.newContext({viewport:{width:1360,height:1000},ignoreHTTPSErrors:true});
    const page=await context.newPage();
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.goto(origin);
    await page.getByRole('heading',{name:'Printer',exact:true}).waitFor();
    assert.equal(await page.locator('input[name=username], .access-label, .access-foot, .access-alternate').count(),0);
    await page.evaluate(()=>document.fonts.ready);
    assert.equal(await page.locator('input[name=password]').evaluate(e=>getComputedStyle(e).outlineStyle),'none');
    await page.screenshot({path:path.join(artifacts,'login-desktop.png')});
    for(const width of [390,320]) {
      await page.setViewportSize({width,height:844});
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      await page.screenshot({path:path.join(artifacts,`login-${width}.png`),fullPage:true});
    }
    await page.setViewportSize({width:1360,height:1000});
    const request=async(url,body,headers={})=>page.evaluate(async({url,body,headers})=>{
      const r=await fetch(url,{method:body===undefined?'GET':'POST',headers:{...(body===undefined?{}:{'Content-Type':'application/json'}),...headers},body:body===undefined?undefined:JSON.stringify(body)});
      return {status:r.status,body:await r.text(),headers:Object.fromEntries(r.headers)};
    },{url,body,headers});
    assert.equal((await request('/printer/api/v1/status')).status,401);
    const assetName=fs.readdirSync(path.join(root,'protected/app/assets')).find(n=>n.endsWith('.js'));
    assert.equal((await request('/assets/'+assetName)).status,401);
    assert.equal((await request('/protected/printer-password.hash')).status,404);
    assert.equal((await request('/_private/access-config.php')).status,404);
    assert.equal((await request('/notes/')).status,404);
    assert.equal((await request('/printer-device.php',{action:'claim',busy:true})).status,401);
    const form=async(extra)=>page.evaluate(async(extra)=>{
      const csrf=document.querySelector('input[name=csrf]').value;
      const r=await fetch('/',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf,...extra})});
      return r.status;
    },extra);
    assert.equal(await form({password:'local-printer-only',username:'rayr'}),400,'Account-selection field was accepted.');
    assert.equal(await form({password:'local-printer-only',csrf:'incorrect'}),403);
    await page.getByRole('textbox',{name:'Password',exact:true}).fill('incorrect-test-password');
    await page.getByRole('button',{name:'Open printer →',exact:true}).click();
    try { await page.getByRole('alert').waitFor({timeout:5000}); } catch (error) { console.error('Login response:', await page.locator('body').innerText()); throw error; }
    await page.getByRole('textbox',{name:'Password',exact:true}).fill('local-printer-only');
    await page.getByRole('button',{name:'Open printer →',exact:true}).click();
    await page.getByRole('heading',{name:'Print',exact:true}).waitFor();
    await page.screenshot({path:path.join(artifacts,'console-desktop.png')});
    const cookies=await context.cookies();
    const cookie=cookies.find(c=>c.name==='__Host-oop_printer');
    assert(cookie && cookie.secure && cookie.httpOnly && cookie.sameSite==='Strict');
    assert(!cookies.some(c=>c.name==='__Host-ray_private'));
    const csrf=await page.locator('meta[name=private-csrf-token]').getAttribute('content');
    assert.equal((await request('/assets/'+assetName)).status,200);
    assert.equal((await request('/printer/api/v1/jobs',{id:'no-csrf',kind:'text',text:'Blocked'})).status,403);
    assert.equal((await request('/printer/api/v1/jobs',{id:'bad-csrf',kind:'text',text:'Blocked'},{'X-CSRF-Token':'wrong'})).status,403);
    const headers={'X-CSRF-Token':csrf};
    // These jobs exist only in the fixture; there is no real printer or live token.
    const queued=await request('/printer/api/v1/jobs',{id:'migration-fixture',kind:'text',source:'remote',text:'Isolated test',timestamp:false},headers);
    assert.equal(queued.status,202);
    const cancelled=await request('/printer/api/v1/jobs/cancel',{id:'migration-fixture'},headers);
    assert.equal(cancelled.status,200);
    assert.equal(JSON.parse(cancelled.body).status,'cancelled');
    const status=await request('/printer/api/v1/status');
    assert.equal(status.status,200);assert.equal(JSON.parse(status.body).mode,'relay');
    const device=await request('/printer-device.php',{action:'claim',version:'test-only',busy:true},{'X-Printer-Token':'d'.repeat(64)});
    assert.equal(device.status,200);
    assert.equal(JSON.parse(device.body).job ?? null,null);
    const fresh=JSON.parse((await request('/printer/api/v1/status')).body);
    assert.equal(fresh.connection.online,true);
    assert.equal(fresh.version,'test-only');
    const readJSON=async(url,body,auth)=>{const r=await request(url,body,auth);return {status:r.status,value:JSON.parse(r.body)};};
    await require('./stream-flow.cjs')((url,body)=>readJSON(url,body,headers),body=>readJSON('/printer-device.php',body,{'X-Printer-Token':'d'.repeat(64)}));
    // Foreign-origin mutation with a valid token must be denied independently of CSRF.
    const rawRequest=(method,url,headers,body)=>new Promise((resolve,reject)=>{const req=http.request({hostname:'127.0.0.1',port:8894,path:url,method,headers:{Host:'printer.objectsonpurpose.com:8896',...headers}},res=>{res.resume();res.on('end',()=>resolve(res.statusCode));});req.on('error',reject);req.end(body);});
    const foreign=await rawRequest('POST','/printer/api/v1/jobs',{Origin:'https://rayemilio.com','Content-Type':'application/json','X-CSRF-Token':csrf,Cookie:`__Host-oop_printer=${cookie.value}`},JSON.stringify({id:'cross-origin',kind:'text',text:'Blocked'}));
    assert.equal(foreign,403);
    // Copying an old site's session shape into the new store must not grant access.
    const staleId='a'.repeat(32);
    fs.writeFileSync(path.join(root,'protected/printer-sessions/sess_'+staleId),'private_user|s:4:"rayr";private_login_at|i:'+Math.floor(Date.now()/1000)+';');
    const stale=await rawRequest('GET','/printer/api/v1/status',{Cookie:`__Host-oop_printer=${staleId}`});
    assert.equal(stale,401);
    assert.equal((await request('/logout/')).status,405);
    const logout=await page.locator('form[action="/logout/"] button');
    await logout.click();
    await page.getByRole('heading',{name:'Printer',exact:true}).waitFor();
    assert.equal((await request('/printer/api/v1/status')).status,401);
    assert.deepEqual(errors,[]);
    const report={login:'passed',passwordOnly:'no account selection',cookie:'dedicated Secure/HttpOnly/SameSite=Strict',protectedAssets:'passed',csrf:'passed',foreignOrigin:'rejected',oldSessionShape:'rejected',queueAndCancellation:'passed in isolated fixture',rasterStream:'393216 bytes uploaded, reconstructed exactly, acknowledged, and retry-safe in isolated fixture',deviceRelay:'passed in isolated fixture',logout:'passed',responsiveWidths:[1360,390,320],consoleErrors:errors,physicalPrintSubmissions:0,environment:'Temporary independent root, test credentials and token; no connection to either production site or hardware'};
    fs.writeFileSync(path.join(artifacts,'verification.json'),JSON.stringify(report,null,2)+'\n');
    console.log(JSON.stringify(report));
  } finally {
    if(browser)await browser.close();if(proxy)await new Promise(r=>proxy.close(r));php.kill();fs.rmSync(root,{recursive:true,force:true});
  }
})().catch(error=>{console.error(error);console.error(phpLogs.slice(-2500));process.exitCode=1;});
