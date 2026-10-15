import subprocess,json,urllib.request,urllib.error,uuid,secrets,time,hashlib,base64
from concurrent.futures import ThreadPoolExecutor
kub=['microk8s','kubectl','-n','reygom-dev']
def sql(q):return subprocess.run(kub+['exec','-i','deployment/reygom-db','--','sh','-lc','psql -v ON_ERROR_STOP=1 -At -U "$POSTGRES_USER" -d "$POSTGRES_DB"'],input=q,text=True,capture_output=True,check=True).stdout.strip()
podname='reygom-access-qa-r3'
pod=json.loads(subprocess.check_output(kub+['get','pod',podname,'-o','json']));base='http://'+pod['status']['podIP']+':8000'
admin=json.loads(sql("SELECT row_to_json(t) FROM (SELECT id,auth_version,organization_id FROM users WHERE username='admin' AND role='superadmin') t;"))
claims=dict(admin,user='admin',role='superadmin',iat=int(time.time()),exp=int(time.time())+900)
php="chdir('/var/www/html');require 'src/Auth/JwtHandler.php';echo (new JwtHandler())->encode(json_decode(base64_decode('"+base64.b64encode(json.dumps(claims).encode()).decode()+"'),true));"
super_token=subprocess.check_output(kub+['exec',podname,'--','php','-r',php],text=True).strip()
count=0;offices=[];users=[];passwords=[];cookies=[];tokens=[]
def check(ok,label):
 global count
 assert ok,label
 count+=1;print('PASS',label,flush=True)
def req(method,path,data=None,token=None,cookie=None,ip='198.51.100.10',origin=None,status=200,office=None):
 headers={'Accept':'application/json','X-Real-IP':ip}
 if office:headers['X-Office-Id']=office
 if token:headers['Authorization']='Bearer '+token
 if cookie:headers['Cookie']=cookie
 if origin:headers['Origin']=origin
 body=None
 if data is not None:headers['Content-Type']='application/json';body=json.dumps(data).encode()
 try:r=urllib.request.urlopen(urllib.request.Request(base+path,data=body,headers=headers,method=method),timeout=15)
 except urllib.error.HTTPError as e:r=e
 raw=r.read();result=json.loads(raw) if raw else {};hdr=r.headers
 if status is not None:check(r.code==status,path+' status '+str(status)+' (actual '+str(r.code)+')')
 return result,hdr,r.code
def root(method,path,data=None,status=200,office=None):return req(method,path,data,super_token,status=status,office=office)[0]
def login(u,cookie=None,ip='198.51.100.10',status=200,password=None):
 result,hdr,code=req('POST','/login',{'username':u['username'],'password':password or u['password']},cookie=cookie,ip=ip,status=status)
 value=hdr.get('Set-Cookie')
 if value:
  cookie=value.split(';')[0];cookies.append(cookie)
  check(all(k in value.lower() for k in ['secure','httponly','samesite=strict','path=/']),'Secure server device cookie')
 if 'token' in result:tokens.append(result['token'])
 return result,cookie
try:
 root('GET','/access')
 req('GET','/access',status=401)
 pre=sql('SELECT count(*) FROM access_devices;')
 req('POST','/login',{'username':'unknown-access-qa','password':'bad'},status=401)
 check(sql('SELECT count(*) FROM access_devices;')==pre,'Wrong credentials do not create devices')
 for suffix in ['a','b']:
  o=root('POST','/offices',{'name':'QA Accesos '+suffix,'slug':'qaaccess'+uuid.uuid4().hex[:12],'country_code':'ES'},201);offices.append(o['id'])
  role='admin' if suffix=='a' else 'agent';password=secrets.token_urlsafe(24);passwords.append(password)
  user=req('POST','/office-users',{'username':'qaaccess'+uuid.uuid4().hex[:12],'password':password,'role':role},super_token,status=None)[0] if False else None
  headers={'Authorization':'Bearer '+super_token,'X-Office-Id':o['id'],'Content-Type':'application/json'}
  username='qaaccess'+uuid.uuid4().hex[:12]
  with urllib.request.urlopen(urllib.request.Request(base+'/office-users',data=json.dumps({'username':username,'password':password,'role':role}).encode(),headers=headers,method='POST'),timeout=15) as rr:user=json.loads(rr.read());check(rr.status==201,'Create scoped QA user')
  users.append(dict(user,username=username,password=password,office=o['id']))
 a,b=users
 pending,ca=login(a,status=403);rid=pending['request_id'];check('token' not in pending,'Pending login has no JWT')
 check(pending['ip']=='198.51.100.10','Verified proxy IP used')
 login(a,ca,status=403)
 check(sql("SELECT count(*) FROM access_requests WHERE user_id='%s';"%a['id'])=='1','Pending retries deduplicated')
 root('POST','/access/requests/'+rid+'/approve',{'duration':'8h'})
 la,ca=login(a,ca);ta=la['token']
 check(la['token']!=super_token,'User-specific JWT issued')
 req('GET','/properties',token=ta,cookie=ca)
 req('GET','/access',token=ta,cookie=ca,status=403)
 req('POST','/access/requests/'+rid+'/approve',{'duration':'permanent'},ta,ca,status=403)
 req('PUT','/access/policies/'+a['id'],{'max_devices':20,'max_ips':20},ta,ca,status=403)
 req('GET','/properties',token=ta,status=401)
 rb,cb=login(b,status=403);root('POST','/access/requests/'+rb['request_id']+'/approve',{'duration':'permanent'})
 lb,cb=login(b,cb);tb=lb['token']
 req('GET','/properties',token=ta,cookie=cb,status=401)
 req('GET','/access',token=tb,cookie=cb,status=403)
 req('GET','/properties?organization_id='+b['office'],token=ta,cookie=ca)
 headers={'Authorization':'Bearer '+ta,'Cookie':ca,'X-Office-Id':b['office']}
 try:urllib.request.urlopen(urllib.request.Request(base+'/properties',headers=headers),timeout=15);check(False,'Cross-office header blocked')
 except urllib.error.HTTPError as e:check(e.code==403,'Cross-office header blocked')
 ip2,_,_=req('GET','/properties',token=ta,cookie=ca,ip='198.51.100.11',status=403)
 root('POST','/access/requests/'+ip2['request_id']+'/approve',{'duration':'8h'},409)
 root('PUT','/access/policies/'+a['id'],{'max_devices':1,'max_ips':2})
 root('POST','/access/requests/'+ip2['request_id']+'/approve',{'duration':'8h'})
 req('GET','/properties',token=ta,cookie=ca,ip='198.51.100.11')
 req('GET','/properties',token=ta,cookie=ca)
 d2,ca2=login(a,status=403)
 root('POST','/access/requests/'+d2['request_id']+'/approve',{'duration':'8h'},409)
 root('PUT','/access/policies/'+a['id'],{'max_devices':2,'max_ips':2})
 root('POST','/access/requests/'+d2['request_id']+'/approve',{'duration':'8h'})
 l2,ca2=login(a,ca2);ta2=l2['token'];req('GET','/properties',token=ta2,cookie=ca2)
 root('PUT','/access/policies/'+a['id'],{'max_devices':1,'max_ips':1},409)
 listing=root('GET','/access');g=next(g for g in listing['grants'] if g['user_id']==a['id'] and g['device_id']!=next(r for r in listing['requests'] if r['id']==rid)['device_id'])
 root('POST','/access/grants/'+g['id']+'/revoke',{})
 req('GET','/properties',token=ta2,cookie=ca2,status=403)
 root('POST','/access/requests/'+d2['request_id']+'/reject',{})
 reject,_=login(a,ca2,status=403);check(reject['status']=='rejected','Rejected request stays rejected')
 # Expiry is enforced on an already issued JWT.
 did=next(r for r in listing['requests'] if r['id']==rid)['device_id']
 sql("UPDATE access_grants SET expires_at=now()-interval '1 second' WHERE user_id='%s' AND device_id='%s' AND ip='198.51.100.10' AND revoked_at IS NULL;"%(a['id'],did))
 req('GET','/properties',token=ta,cookie=ca,status=403)
 root('POST','/access/requests/'+rid+'/approve',{'duration':'custom','expires_at':'2020-01-01T00:00:00Z'},422)
 root('POST','/access/requests/'+rid+'/approve',{'duration':'custom','expires_at':'invalid'},422)
 root('POST','/access/requests/'+rid+'/approve',{'duration':'custom','expires_at':'2030-02-31T00:00:00Z'},422)
 root('POST','/access/requests/'+rid+'/approve',{'duration':'permanent'})
 req('GET','/properties',token=ta,cookie=ca)
 root('PUT','/access/policies/'+a['id'],{'max_devices':0,'max_ips':1},422)
 # Concurrent approvals cannot exceed a user's maximum.
 c3,ca3=login(a,status=403);c4,ca4=login(a,status=403)
 def approve(r):return req('POST','/access/requests/'+r['request_id']+'/approve',{'duration':'1h'},super_token,status=None)[2]
 with ThreadPoolExecutor(max_workers=2) as pool:codes=list(pool.map(approve,[c3,c4]))
 check(sorted(codes)==[200,409],'Concurrent approvals respect device limit')
 # Bad proxy source must not gain access merely by forging forwarding headers.
 phpip="putenv('ACCESS_REQUIRE_PROXY=0');require '/var/www/html/src/Services/AccessGuard.php';$_SERVER['REMOTE_ADDR']='203.0.113.9';$_SERVER['HTTP_X_REAL_IP']='198.51.100.10';echo AccessGuard::ip();"
 actual=subprocess.check_output(kub+['exec',podname,'--','php','-r',phpip],text=True).strip();check(actual=='203.0.113.9','Untrusted forwarding header ignored')
 blocked=subprocess.check_output(kub+['exec',podname,'--','php','-r',phpip.replace("ACCESS_REQUIRE_PROXY=0","ACCESS_REQUIRE_PROXY=1")],text=True).strip()
 check('proxy' in json.loads(blocked)['message'],'Mandatory trusted proxy rejects untrusted source')
 _,cors,_=req('OPTIONS','/access',origin='https://crm-dev.reygom.com')
 check(cors.get('Access-Control-Allow-Origin')=='https://crm-dev.reygom.com' and cors.get('Access-Control-Allow-Credentials')=='true','Credential CORS restricted to exact CRM origin')
 _,cors,_=req('OPTIONS','/access',origin='https://attacker.invalid',status=403)
 check(cors.get('Access-Control-Allow-Origin') is None,'Unknown CORS origin not reflected')
 root('POST','/access/devices/'+did+'/revoke',{})
 req('GET','/properties',token=ta,cookie=ca,ip='198.51.100.11',status=403)
 root('POST','/access/requests/'+rid+'/approve',{'duration':'8h'})
 root('PUT','/office-users/'+b['id'],{'role':'agent','is_active':False},office=b['office'])
 req('GET','/properties',token=tb,cookie=cb,status=401)
 root('PUT','/office-users/'+b['id'],{'role':'agent','is_active':True},office=b['office'])
 req('GET','/properties',token=tb,cookie=cb,status=401)
 lb,cb=login(b,cb);tb=lb['token']
 req('POST','/logout',token=tb,cookie=cb)
 req('GET','/properties',token=tb,cookie=cb,status=401)
 events=root('GET','/audit?user='+a['id'])['rows'];encoded=json.dumps(events)
 check({'access.requested','access.approved','access.rejected','access.revoked','access.policy_updated','access.device_revoked'}<={e['action'] for e in events},'All access decisions audited')
 check(not any(s in encoded for s in passwords+cookies+tokens),'Audit contains no passwords device cookies or JWTs')
 check(any(e['actor_id']==admin['id'] and e['subject_id']==a['id'] and e['metadata'].get('ip') for e in events if e['action']=='access.approved'),'Approval attributed to Super admin with target and IP')
 print('ACCESS_HTTP_QA_PASSED',count,flush=True)
finally:
 ids=','.join("'%s'"%u['id'] for u in users) or 'NULL';oids=','.join("'%s'"%o for o in offices) or 'NULL'
 q="BEGIN;SET LOCAL reygom.audit_maintenance='on';"
 for table in ['access_grants','access_requests','access_devices','user_access_policies']:q+='DELETE FROM '+table+' WHERE user_id IN ('+ids+');'
 q+='DELETE FROM users WHERE id IN ('+ids+');DELETE FROM organizations WHERE id IN ('+oids+');DELETE FROM office_audit WHERE organization_id IN ('+oids+');'
 for t in tokens:q+="DELETE FROM revoked_sessions WHERE token_hash='%s';"%hashlib.sha256(t.encode()).hexdigest()
 sql(q+'COMMIT;');print('OWN_ACCESS_QA_FIXTURES_REMOVED',flush=True)
