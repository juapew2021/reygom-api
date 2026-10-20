import subprocess,json,urllib.request,urllib.error,uuid,secrets,time
kub=['microk8s','kubectl','-n','reygom-dev']
def sql(query):
 r=subprocess.run(kub+['exec','-i','deployment/reygom-db','--','sh','-lc','psql -v ON_ERROR_STOP=1 -At -U "$POSTGRES_USER" -d "reygom_notifications_qa_20261016"'],input=query,text=True,capture_output=True,check=True)
 return r.stdout.strip()
admin=json.loads(sql("SELECT row_to_json(t) FROM (SELECT id,auth_version FROM users WHERE username='admin' AND role='superadmin') t;"))
claims={'id':admin['id'],'user':'admin','role':'superadmin','organization_id':sql("SELECT organization_id FROM users WHERE id='%s';"%admin['id']),'auth_version':admin['auth_version'],'iat':int(time.time()),'exp':int(time.time())+600}
php="chdir('/var/www/html');require 'src/Auth/JwtHandler.php';echo (new JwtHandler())->encode(json_decode(base64_decode('"+__import__('base64').b64encode(json.dumps(claims).encode()).decode()+"'),true));"
token=subprocess.check_output(kub+['exec','reygom-notifications-qa','--','php','-r',php],text=True).strip()
pod=json.loads(subprocess.check_output(kub+['get','pod','reygom-notifications-qa','-o','json']))
base='http://'+pod['status']['podIP']+':8000'
counter=0
_login_cookies={}
_token_cookies={}
def _raw_request(method,path,data=None,auth=None,office=None):
 headers={'Accept':'application/json','X-Real-IP':'198.51.100.50'}
 if auth:headers['Authorization']='Bearer '+auth
 if office:headers['X-Office-Id']=office
 username=data.get('username') if path=='/login' and isinstance(data,dict) else None
 cookie=_login_cookies.get(username) if username else _token_cookies.get(auth)
 if cookie:headers['Cookie']=cookie
 body=None
 if data is not None:headers['Content-Type']='application/json';body=json.dumps(data).encode()
 req=urllib.request.Request(base+path,data=body,headers=headers,method=method)
 try:r=urllib.request.urlopen(req,timeout=15)
 except urllib.error.HTTPError as e:r=e
 raw=r.read()
 if username and r.headers.get('Set-Cookie'):_login_cookies[username]=r.headers['Set-Cookie'].split(';')[0]
 try:result=json.loads(raw)
 except ValueError:result={'message':'Non-JSON response'}
 if username and r.code==200 and 'token' in result:_token_cookies[result['token']]=_login_cookies.get(username,'')
 return r.code,result

def request(method,path,data=None,auth=None,office=None):
 code,result=_raw_request(method,path,data,auth,office)
 if path=='/login' and code==403 and isinstance(result,dict) and result.get('code')=='ACCESS_APPROVAL_REQUIRED' and result.get('status')=='pending':
  approved,_result=_raw_request('POST','/access/requests/'+result['request_id']+'/approve',{'duration':'1h'},token)
  if approved!=200:raise AssertionError('QA approval through real endpoint failed')
  code,result=_raw_request(method,path,data,auth,office)
 return code,result

def check(condition,label):
 global counter
 if not condition:raise AssertionError(label)
 counter+=1;print('PASS',label,flush=True)
def expect(method,path,data=None,auth=None,office=None,status=200,label='request'):
 code,result=request(method,path,data,auth,office)
 if code!=status:raise AssertionError(label+' status '+str(code)+' '+str(result.get('message','') if isinstance(result,dict) else ''))
 check(True,label);return result
import base64
count_start=counter
expect('GET','/notifications',status=401,label='Anonymous inbox denied')
expect('POST','/notifications',{},status=401,label='Anonymous send denied')
offices=[];people=[]
for suffix in ['a','b']:
 offices.append(expect('POST','/offices',{'name':'QA Notifications '+suffix,'slug':'qanotify'+uuid.uuid4().hex[:12],'country_code':'ES'},token,status=201,label='Create isolated office'))
for idx,role in [(0,'admin'),(0,'agent'),(0,'agent'),(1,'admin'),(1,'agent')]:
 password=secrets.token_urlsafe(24);username='qanotify'+uuid.uuid4().hex[:12]
 u=expect('POST','/office-users',{'username':username,'password':password,'role':role},token,offices[idx]['id'],201,'Create role fixture')
 login=expect('POST','/login',{'username':username,'password':password},status=200,label='Login approved QA device')
 people.append(dict(u,token=login['token'],password=password))
aa,a1,a2,ba,b1=people
root_options=expect('GET','/notifications/recipients',auth=token,label='Super admin target directory')['recipients'];check(len(root_options)==6,'Super admin sees all active roles across offices')
opts=expect('GET','/notifications/recipients',auth=aa['token'],label='Office administrator directory')['recipients'];check({u['id'] for u in opts}=={a1['id'],a2['id']},'Administrator targets own agents only')
opts=expect('GET','/notifications/recipients',auth=a1['token'],label='Agent team directory')['recipients'];check({u['id'] for u in opts}=={aa['id'],a1['id'],a2['id']},'Agent targets self, colleagues and administrator')
def payload(title='QA notification',ids=None,audience='selected',key=None):return {'title':title,'body':'QA_SECRET_NOTIFICATION_BODY <script>never execute</script>','audience':audience,'recipient_ids':ids or [],'request_key':key or str(uuid.uuid4())}
def send(person,data,status=201,label='Send notification'):return expect('POST','/notifications',data,person['token'] if isinstance(person,dict) else person,status=status,label=label)
def inbox(person):return expect('GET','/notifications',auth=person['token'] if isinstance(person,dict) else person,label='Read own inbox')
for person,target in [(aa,aa),(aa,ba),(aa,b1),(a1,ba),(a1,b1)]:send(person,payload(ids=[target['id']]),403,'Out-of-scope recipient denied')
before=sql('SELECT count(*) FROM internal_notifications;');send(a1,payload(ids=[a2['id'],b1['id']]),403,'Mixed allowed and forbidden recipients rejected atomically');check(sql('SELECT count(*) FROM internal_notifications;')==before,'No partial delivery after rejected send')
message=payload('QA targeted',[a1['id']]);message['sender_id']=admin['id'];first=send(aa,message,label='Administrator notifies selected agent')
repeat=send(aa,message,label='Same request safely retried');check(repeat['duplicate'] and repeat['batch_id']==first['batch_id'],'Retry deduplicated')
changed=dict(message,body='different');send(aa,changed,409,'Idempotency key cannot change content')
rows=inbox(a1)['rows'];check(len(rows)==1 and rows[0]['sender_name']==aa['username'],'Sender identity cannot be spoofed')
nid=rows[0]['id'];expect('POST','/notifications/'+nid+'/read',{},a2['token'],status=404,label='Other recipient cannot mark another inbox read')
check(expect('GET','/notifications/unread',auth=a1['token'],label='Unread counter')['unread']==1,'Unread count starts at one')
expect('POST','/notifications/'+nid+'/read',{},a1['token'],label='Recipient marks message read');check(expect('GET','/notifications/unread',auth=a1['token'],label='Unread after reading')['unread']==0,'Reading clears own counter')
sent=expect('GET','/notifications/sent',auth=aa['token'],label='Sender delivery summary');check(int(sent['rows'][0]['read_count'])==1,'Sender sees aggregate read receipt')
for bad in [dict(payload(ids=[a1['id']]),title=''),dict(payload(ids=[a1['id']]),body='x'*4001),dict(payload(ids=[a1['id']]),title='x\nInjected'),dict(payload(ids=[a1['id']]),request_key='bad'),payload(ids=['not-a-uuid'])]:send(aa,bad,422,'Invalid message rejected')
bulk=send(a1,payload('QA own team',audience='all'),label='Agent notifies own team');check(bulk['recipients']==3,'Agent team delivery includes self and administrator')
bulk=send(aa,payload('QA all agents',audience='all'),label='Administrator notifies own agents');check(bulk['recipients']==2,'Administrator broadcast excludes own administrator and foreign office')
bulk=send(token,payload('QA platform broadcast',audience='all'),label='Super admin notifies everyone in isolated DB');check(bulk['recipients']==6,'Platform broadcast includes every active role')
for person in [token]+people:check(any(x['title']=='QA platform broadcast' for x in inbox(person)['rows']),'Global delivery reaches recipient role')
expect('PUT','/office-users/'+a2['id'],{'role':'agent','is_active':False},aa['token'],label='Deactivate fixture agent')
opts=expect('GET','/notifications/recipients',auth=aa['token'],label='Directory after deactivation')['recipients'];check({u['id'] for u in opts}=={a1['id']},'Inactive account excluded from directory')
send(aa,payload(ids=[a2['id']]),403,'Inactive target rejected');expect('GET','/notifications',auth=a2['token'],status=401,label='Inactive account session rejected')
expect('PUT','/office-users/'+a2['id'],{'role':'agent','is_active':True},aa['token'],label='Reactivate fixture agent')
expect('PUT','/offices/'+offices[1]['id'],{'name':offices[1]['name'],'country_code':'ES','status':'inactive'},token,label='Suspend isolated office')
opts=expect('GET','/notifications/recipients',auth=token,label='Directory after suspension')['recipients'];check(not any(u['id'] in [ba['id'],b1['id']] for u in opts),'Inactive office excluded globally')
send(token,payload(ids=[b1['id']]),403,'Even Super admin cannot target inactive office accounts')
expect('PUT','/offices/'+offices[1]['id'],{'name':offices[1]['name'],'country_code':'ES','status':'active'},token,label='Reactivate isolated office')
foreign_before=expect('GET','/notifications/unread',auth=b1['token'],label='Other office unread before')['unread']
expect('POST','/notifications/read-all',{},a1['token'],label='Mark own inbox read');check(expect('GET','/notifications/unread',auth=a1['token'],label='Own unread reset')['unread']==0,'Mark-all clears only own inbox');check(expect('GET','/notifications/unread',auth=b1['token'],label='Other office unread unchanged')['unread']==foreign_before,'Mark-all cannot affect foreign inbox')
private=send(ba,payload('QA PRIVATE B',[b1['id']]),label='Private foreign-office message')
for path in ['/notifications?recipient_id='+b1['id'],'/notifications/sent?sender_id='+ba['id']]:
 data=expect('GET',path,auth=a1['token'],label='Forged inbox/outbox filter ignored');check('QA PRIVATE B' not in json.dumps(data),'Foreign message remains private')
# Seed pagination entirely within the disposable database, never the live DEV DB.
batch=sql("INSERT INTO notification_batches(sender_id,request_key,payload_hash) VALUES('%s',uuid_generate_v4(),repeat('0',64)) RETURNING id;"%admin['id']).splitlines()[0]
sql("WITH b AS (INSERT INTO notification_batches(sender_id,request_key,payload_hash) SELECT '%s'::uuid,uuid_generate_v4(),repeat('0',64) FROM generate_series(1,27) RETURNING id) INSERT INTO internal_notifications(batch_id,sender_id,sender_name,sender_role,recipient_id,recipient_office_id,title,body) SELECT b.id,'%s'::uuid,'QA','superadmin','%s'::uuid,'%s'::uuid,'QA page '||b.id,'QA' FROM b;"%(admin['id'],admin['id'],a1['id'],offices[0]['id']))
p1=expect('GET','/notifications?page=1',auth=a1['token'],label='First inbox page');p2=expect('GET','/notifications?page=2',auth=a1['token'],label='Second inbox page');check(len(p1['rows'])==25 and not({r['id'] for r in p1['rows']}&{r['id'] for r in p2['rows']}),'Pagination bounded and stable')
expect('GET','/notifications?page=0',auth=a1['token'],status=422,label='Invalid page rejected')
# Column-level grants and RLS remain effective outside the route logic.
q="BEGIN;SELECT set_config('reygom.user_id','%s',true);SET LOCAL ROLE reygom_office_runtime;UPDATE internal_notifications SET body='forbidden' WHERE recipient_id='%s';ROLLBACK;"%(a1['id'],a1['id'])
try:sql(q);check(False,'Message body must be immutable to runtime')
except subprocess.CalledProcessError as e:check('permission denied' in e.stderr.lower(),'Runtime cannot alter delivered message contents')
q="BEGIN;SELECT set_config('reygom.user_id','%s',true);SET LOCAL ROLE reygom_office_runtime;INSERT INTO internal_notifications(batch_id,sender_id,sender_name,sender_role,recipient_id,recipient_office_id,title,body) VALUES('%s','%s','QA','agent','%s','%s','forbidden','forbidden');ROLLBACK;"%(a1['id'],batch,a1['id'],b1['id'],offices[1]['id'])
try:sql(q);check(False,'RLS must deny foreign target')
except subprocess.CalledProcessError as e:check('row-level security' in e.stderr.lower(),'Database RLS independently blocks cross-office delivery')
current=int(sql("SELECT count(*) FROM notification_batches WHERE sender_id='%s' AND created_at>now()-interval '1 hour';"%a1['id']))
for n in range(20-current):sql("INSERT INTO notification_batches(sender_id,request_key,payload_hash) VALUES('%s',uuid_generate_v4(),repeat('1',64));"%a1['id'])
send(a1,payload('QA rate limit',[a1['id']]),429,'Per-sender rate limit enforced')
audit=expect('GET','/audit',auth=token,label='Super admin audits notification sends');text=json.dumps(audit);check('notification.sent' in text and 'QA_SECRET_NOTIFICATION_BODY' not in text,'Send audit contains event metadata without message contents')
print('NOTIFICATIONS_HTTP_QA_PASSED',counter-count_start,flush=True)
