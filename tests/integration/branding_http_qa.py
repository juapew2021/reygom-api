import subprocess,json,urllib.request,urllib.error,uuid,secrets,time
kub=['microk8s','kubectl','-n','reygom-dev']
def sql(query):
 r=subprocess.run(kub+['exec','-i','deployment/reygom-db','--','sh','-lc','psql -v ON_ERROR_STOP=1 -At -U "$POSTGRES_USER" -d "$POSTGRES_DB"'],input=query,text=True,capture_output=True,check=True)
 return r.stdout.strip()
admin=json.loads(sql("SELECT row_to_json(t) FROM (SELECT id,auth_version FROM users WHERE username='admin' AND role='superadmin') t;"))
claims={'id':admin['id'],'user':'admin','role':'superadmin','organization_id':sql("SELECT organization_id FROM users WHERE id='%s';"%admin['id']),'auth_version':admin['auth_version'],'iat':int(time.time()),'exp':int(time.time())+600}
php="chdir('/var/www/html');require 'src/Auth/JwtHandler.php';echo (new JwtHandler())->encode(json_decode(base64_decode('"+__import__('base64').b64encode(json.dumps(claims).encode()).decode()+"'),true));"
token=subprocess.check_output(kub+['exec','reygom-branding-qa','--','php','-r',php],text=True).strip()
pod=json.loads(subprocess.check_output(kub+['get','pod','reygom-branding-qa','-o','json']))
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
office_ids=[];user_ids=[];property_ids=[];lead_ids=[];submission_ids=[]
prefix='qaoffice'+uuid.uuid4().hex[:10]
import base64
try:
 expect('GET','/office-branding',status=401,label='Anonymous branding denied')
 expect('GET','/profile',status=401,label='Anonymous profile denied')
 for suffix in ['a','b']:
  o=expect('POST','/offices',{'name':'QA Marca '+suffix,'slug':prefix+suffix,'country_code':'ES'},token,status=201,label='Create isolated QA office');office_ids.append(o['id'])
 people=[]
 for office,role in [(office_ids[0],'admin'),(office_ids[0],'agent'),(office_ids[1],'admin'),(office_ids[1],'agent')]:
  username=prefix+str(len(people));password=secrets.token_urlsafe(24)
  u=expect('POST','/office-users',{'username':username,'password':password,'role':role},token,office,201,'Create scoped QA account');user_ids.append(u['id'])
  login=expect('POST','/login',{'username':username,'password':password},status=200,label='Login approved QA device')
  people.append(dict(u,token=login['token']))
 aa,ag,ba,bg=people
 a=expect('GET','/office-branding',auth=aa['token'],label='Administrator reads default brand');check(a['can_edit'] is True and a['version']==0,'Default brand editable by manager')
 changed={'display_name':'QA Inmobiliaria A','primary_color':'#123456','sidebar_color':'#fafafa','version':0}
 expect('PUT','/office-branding',changed,aa['token'],label='Administrator customizes own office')
 seen=expect('GET','/office-branding',auth=ag['token'],label='Agent receives own office theme');check(seen['display_name']=='QA Inmobiliaria A' and seen['can_edit'] is False,'Agent inherits office brand without edit permission')
 foreign=expect('GET','/office-branding',auth=ba['token'],label='Other office reads its own brand');check(foreign['version']==0 and foreign['display_name']=='','Another office remains unchanged')
 expect('PUT','/office-branding',changed,ag['token'],status=403,label='Agent cannot change brand')
 expect('PUT','/office-branding',changed,aa['token'],status=409,label='Stale administrator edit rejected')
 expect('GET','/office-branding',auth=aa['token'],office=office_ids[1],status=403,label='Forged office header rejected')
 invalid=dict(changed,version=1,primary_color='red;display:none');expect('PUT','/office-branding',invalid,aa['token'],status=422,label='CSS injection rejected')
 png=subprocess.check_output(kub+['exec','reygom-branding-qa','--','php','-r','$i=imagecreatetruecolor(80,40);imagefill($i,0,0,imagecolorallocate($i,32,96,192));imagepng($i);'])
 def upload(path,person,content=png,name='test.png',mime='image/png',extra=None,status=200,label='upload'):
  boundary='ReyGomQA'+uuid.uuid4().hex
  body=b''
  for key,value in (extra or {}).items():body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n').encode()
  body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="image"; filename="'+name+'"\r\nContent-Type: '+mime+'\r\n\r\n').encode()+content+('\r\n--'+boundary+'--\r\n').encode()
  h={'Authorization':'Bearer '+person['token'],'Cookie':_token_cookies[person['token']],'X-Real-IP':'198.51.100.50','Content-Type':'multipart/form-data; boundary='+boundary}
  try:r=urllib.request.urlopen(urllib.request.Request(base+path,data=body,headers=h,method='POST'),timeout=25)
  except urllib.error.HTTPError as e:r=e
  result=json.loads(r.read());check(r.code==status,label+' status '+str(status));return result
 logo=upload('/office-branding/logo',aa,name='filename.php',label='Actual PNG upload ignores executable filename')
 check(logo['logo'].startswith('data:image/webp;base64,'),'Logo is sanitized WebP data')
 raw=base64.b64decode(logo['logo'].split(',')[1]);check(raw[:4]==b'RIFF' and raw[8:12]==b'WEBP','Stored logo has actual WebP signature')
 seen=expect('GET','/office-branding',auth=ag['token'],label='Agent sees updated logo');check(seen['logo']==logo['logo'],'Logo shared inside office only')
 check(expect('GET','/office-branding',auth=ba['token'],label='Foreign logo remains absent')['logo'] is None,'Other office logo is not exposed')
 upload('/office-branding/logo',ag,status=403,label='Agent logo upload blocked')
 upload('/office-branding/logo',aa,content=b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',mime='image/png',status=422,label='Disguised active SVG rejected')
 upload('/office-branding/logo',aa,content=b'x'*(2*1024*1024+1),status=422,label='Oversized image rejected')
 photo=upload('/profile/photo',ag,extra={'user_id':aa['id'],'organization_id':office_ids[1]},label='Agent uploads own photo despite forged target fields')
 check(photo['username']==ag['username'] and photo['photo'].startswith('data:image/webp;base64,'),'Uploaded photo belongs to authenticated agent')
 check(expect('GET','/profile',auth=aa['token'],label='Administrator profile unaffected')['photo'] is None,'Agent cannot alter administrator photo')
 check(expect('GET','/profile',auth=bg['token'],label='Other agent profile unaffected')['photo'] is None,'Agent cannot alter foreign office profile')
 own=expect('GET','/profile?user_id='+aa['id'],auth=ag['token'],label='Forged profile query returns own account');check(own['username']==ag['username'],'Read target cannot be forged')
 audit=expect('GET','/audit?office='+office_ids[0],auth=token,label='Super admin reads branding audit')
 serialized=json.dumps(audit);check('data:image' not in serialized and 'base64' not in serialized,'Audit never stores image contents')
 print('BRANDING_HTTP_QA_PASSED',counter,flush=True)
finally:
 def ids(values):return ','.join("'%s'"%str(uuid.UUID(v)) for v in values) or 'NULL'
 cleanup="BEGIN; SET LOCAL reygom.audit_maintenance='on';"
 for table in ['user_profiles','office_branding']:cleanup+='DELETE FROM '+table+' WHERE organization_id IN ('+ids(office_ids)+');'
 for table in ['access_grants','access_requests','access_devices','user_access_policies']:cleanup+='DELETE FROM '+table+' WHERE user_id IN ('+ids(user_ids)+');'
 cleanup+='DELETE FROM users WHERE id IN ('+ids(user_ids)+');DELETE FROM organizations WHERE id IN ('+ids(office_ids)+');DELETE FROM office_audit WHERE organization_id IN ('+ids(office_ids)+');COMMIT;'
 sql(cleanup);print('EXACT_BRANDING_QA_FIXTURES_REMOVED',flush=True)
