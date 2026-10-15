import subprocess,json,urllib.request,urllib.error,uuid,secrets,time
kub=['microk8s','kubectl','-n','reygom-dev']
def sql(query):
 r=subprocess.run(kub+['exec','-i','deployment/reygom-db','--','sh','-lc','psql -v ON_ERROR_STOP=1 -At -U "$POSTGRES_USER" -d "$POSTGRES_DB"'],input=query,text=True,capture_output=True,check=True)
 return r.stdout.strip()
admin=json.loads(sql("SELECT row_to_json(t) FROM (SELECT id,auth_version FROM users WHERE username='admin' AND role='superadmin') t;"))
claims={'id':admin['id'],'user':'admin','role':'superadmin','organization_id':sql("SELECT organization_id FROM users WHERE id='%s';"%admin['id']),'auth_version':admin['auth_version'],'iat':int(time.time()),'exp':int(time.time())+600}
php="chdir('/var/www/html');require 'src/Auth/JwtHandler.php';echo (new JwtHandler())->encode(json_decode(base64_decode('"+__import__('base64').b64encode(json.dumps(claims).encode()).decode()+"'),true));"
token=subprocess.check_output(kub+['exec','reygom-history-qa-r3','--','php','-r',php],text=True).strip()
pod=json.loads(subprocess.check_output(kub+['get','pod','reygom-history-qa-r3','-o','json']))
base='http://'+pod['status']['podIP']+':8000'
counter=0
def request(method,path,data=None,auth=None,office=None):
 headers={'Accept':'application/json'}
 if auth:headers['Authorization']='Bearer '+auth
 if office:headers['X-Office-Id']=office
 body=None
 if data is not None:headers['Content-Type']='application/json';body=json.dumps(data).encode()
 req=urllib.request.Request(base+path,data=body,headers=headers,method=method)
 try:
  with urllib.request.urlopen(req,timeout=12) as r:return r.status,json.loads(r.read())
 except urllib.error.HTTPError as e:
  raw=e.read()
  try:return e.code,json.loads(raw)
  except ValueError:return e.code,{'message':'Non-JSON error response'}
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
try:
 a=expect('POST','/offices',{'name':'QA Oficina España','slug':prefix+'a','country_code':'ES'},token,status=201,label='Super admin creates ES office');office_ids.append(a['id'])
 b=expect('POST','/offices',{'name':'QA Oficina Colombia','slug':prefix+'b','country_code':'CO'},token,status=201,label='Super admin creates CO office');office_ids.append(b['id'])
 def create_user(suffix,role,office):
  username=prefix+suffix;password=secrets.token_urlsafe(24)
  row=expect('POST','/office-users',{'username':username,'password':password,'role':role},token,office,201,'Create '+suffix);user_ids.append(row['id'])
  login=expect('POST','/login',{'username':username,'password':password},status=200,label='Login '+suffix)
  return {'id':row['id'],'username':username,'password':password,'token':login['token']}
 aa=create_user('admina','admin',a['id']);ab=create_user('adminb','admin',b['id'])
 x=create_user('agenta','agent',a['id']);y=create_user('agenta2','agent',a['id']);z=create_user('agentb','agent',b['id'])
 expect('GET','/offices',auth=aa['token'],status=403,label='Office admin cannot list all offices')
 expect('POST','/offices',{'name':'Forbidden','slug':prefix+'bad','country_code':'CO'},aa['token'],status=403,label='Office admin cannot create office')
 expect('POST','/office-users',{'username':prefix+'bad','password':secrets.token_urlsafe(20),'role':'admin'},aa['token'],status=422,label='Office admin cannot create administrators')
 extra=expect('POST','/office-users',{'username':prefix+'managed','password':secrets.token_urlsafe(20),'role':'agent'},aa['token'],status=201,label='Office admin creates own agent');user_ids.append(extra['id'])
 check(extra['organization_id']==a['id'] and extra['role']=='agent','New agent automatically belongs to administrators office')
 expect('GET','/office-users',auth=x['token'],status=403,label='Agent cannot manage users')
 expect('GET','/properties',auth=aa['token'],office=b['id'],status=403,label='Forged office header rejected')
 def property_for(person,title):
  row=expect('POST','/properties',{'title':title,'price':1000,'city':'Vic','province':'Barcelona','country_code':'ES','status':'Oculto','operation_type':'Alquiler','property_type':'Piso'},person['token'],status=201,label='Create hidden property '+title)
  pid=row.get('id') or row.get('property',{}).get('id');check(bool(pid),'Created property id');property_ids.append(pid);return pid
 pa=property_for(x,'QA A1');pa2=property_for(y,'QA A2');pb=property_for(z,'QA B')
 rows=expect('GET','/properties',auth=x['token'],label='Read shared office portfolio');ids={r['id'] for r in rows}
 check(pa in ids and pa2 in ids and pb not in ids,'Agent sees colleagues but not another office')
 for verb in ['GET','PUT','DELETE']:
  expect(verb,'/properties/'+pb,{'title':'Forbidden','price':1} if verb=='PUT' else None,x['token'],status=404,label=verb+' foreign property denied')
 def lead_for(person,name,pid):
  row=expect('POST','/leads',{'full_name':name,'contact_info':{},'property_interest_id':pid},person['token'],status=201,label='Create scoped lead '+name);lead_ids.append(row['id']);return row['id']
  
 la=lead_for(x,'QA Lead A',pa);lb=lead_for(z,'QA Lead B',pb)
 leads=expect('GET','/leads',auth=y['token'],label='Read shared contacts');ids={r['id'] for r in leads};check(la in ids and lb not in ids,'Contacts isolated by office')
 for verb in ['GET','PUT','DELETE']:
  expect(verb,'/leads/'+lb,{'full_name':'Forbidden','contact_info':{}} if verb=='PUT' else None,x['token'],status=404,label=verb+' foreign contact denied')
 expect('POST','/leads',{'full_name':'Forbidden link','contact_info':{},'property_interest_id':pb},x['token'],status=422,label='Foreign property association rejected')
 image=expect('POST','/properties/'+pb+'/images',{'image_url':'https://example.invalid/qa-image.jpg'},z['token'],status=201,label='Create scoped image')
 image_id=image['id']
 expect('GET','/property-images/'+image_id,auth=x['token'],status=404,label='Foreign image hidden')
 expect('PUT','/properties/'+pb+'/images/order',{'image_ids':[image_id]},x['token'],status=404,label='Foreign image order protected')
 expect('DELETE','/property-images/'+image_id,auth=x['token'],status=404,label='Foreign image delete denied')
 expect('GET','/property-images/'+image_id,auth=z['token'],label='Foreign image remained intact')
 dashboard=expect('GET','/dashboard',auth=x['token'],label='Office dashboard');check(dashboard['properties_count']==2 and dashboard['leads_count']==1,'Office dashboard counts only own data')
 users=expect('GET','/office-users',auth=aa['token'],label='Admin sees office team');check(all(u['organization_id']==a['id'] and 'password' not in u for u in users),'User listing scoped and excludes passwords')
 # Private submission files must be authorized through the scoped row before disk access.
 import base64
 payload={'title':'QA office submission','description':'Synthetic DEV isolation test','city':'Vic','province':'Barcelona','country':'España','country_code':'ES','state':'Barcelona','state_code':'08','price':1000,'bedrooms':1,'bathrooms':1,'built_area':30,'operation_type':'Alquiler','property_type':'Piso','contact_name':'QA','phone':'930000000','email':'qa@example.invalid'}
 for office in [a,b]:
  sid=str(uuid.uuid4());submission_ids.append(sid)
  packed=base64.b64encode(json.dumps(payload).encode()).decode()
  sql("INSERT INTO property_submissions(id,payload,email,verification_expires_at,status,verified_at,organization_id) VALUES('%s',convert_from(decode('%s','base64'),'UTF8')::jsonb,'qa@example.invalid',now()+interval '1 day','pending_review',now(),'%s');"%(sid,packed,office['id']))
  if office==b:
   code="$d='/var/www/media/submissions-private/"+sid+"';mkdir($d,0700,true);file_put_contents($d.'/verification.json',json_encode(['test_only'=>true,'verification_token'=>'qa-only-token']));"
   subprocess.run(kub+['exec','reygom-history-qa-r3','--','php','-r',code],check=True,stdout=subprocess.DEVNULL)
 inbox=expect('GET','/submissions',auth=aa['token'],label='Office admin reads own submissions')
 check(submission_ids[0] in {r['id'] for r in inbox} and submission_ids[1] not in {r['id'] for r in inbox},'Submission inbox isolated')
 expect('GET','/submissions/'+submission_ids[1]+'/outbox',auth=aa['token'],status=404,label='Foreign private verification file protected')
 expect('POST','/submissions/'+submission_ids[1]+'/review',{'decision':'approve','note':'QA'},aa['token'],status=404,label='Foreign submission approval denied')
 approved=expect('POST','/submissions/'+submission_ids[0]+'/review',{'decision':'approve','note':'QA'},aa['token'],label='Approve submission inside office')
 property_ids.append(approved['property_id'])
 check(sql("SELECT organization_id FROM properties WHERE id='%s';"%approved['property_id'])==a['id'],'Approved property belongs to reviewing office')
 sql("UPDATE properties SET status='Oculto' WHERE id='%s';"%approved['property_id'])
 expect('PUT','/office-users/'+ab['id'],{'role':'agent','is_active':False},aa['token'],status=404,label='Cannot edit another office user')
 expect('PUT','/office-users/'+x['id'],{'role':'agent','is_active':False},aa['token'],label='Admin deactivates own agent')
 expect('GET','/properties',auth=x['token'],status=401,label='Existing session revoked after deactivation')
 expect('POST','/login',{'username':x['username'],'password':x['password']},status=401,label='Inactive user cannot log in')
 expect('PUT','/office-users/'+x['id'],{'role':'agent','is_active':True},aa['token'],label='Reactivate agent')
 expect('GET','/properties',auth=x['token'],status=401,label='Old token remains revoked after reactivation')
 expect('POST','/login',{'username':x['username'],'password':x['password']},label='New login succeeds after reactivation')
 expect('PUT','/office-users/'+aa['id'],{'role':'agent','is_active':True},token,a['id'],422,'Last office administrator protected')
 expect('PUT','/offices/'+b['id'],{'name':b['name'],'country_code':'CO','status':'inactive'},token,label='Super admin suspends office')
 expect('GET','/leads',auth=z['token'],status=403,label='Suspended office access denied')
 expect('PUT','/offices/'+b['id'],{'name':b['name'],'country_code':'CO','status':'active'},token,label='Super admin reactivates office')
 expect('GET','/properties/'+pb,auth=z['token'],label='Other office property remained intact')
 print('OFFICE_HTTP_QA_PASSED',counter,flush=True)
finally:
 # Exact generated UUIDs only. No real data or user credentials are modified.
 def ids(values):return ','.join("'%s'"%str(uuid.UUID(v)) for v in values) or 'NULL'
 cleanup="BEGIN; SET LOCAL reygom.audit_maintenance='on';"
 cleanup+='DELETE FROM property_submissions WHERE id IN ('+ids(submission_ids)+');'
 cleanup+='DELETE FROM property_images WHERE property_id IN ('+ids(property_ids)+');'
 cleanup+='DELETE FROM leads WHERE id IN ('+ids(lead_ids)+');'
 cleanup+='DELETE FROM properties WHERE id IN ('+ids(property_ids)+');'
 cleanup+='DELETE FROM users WHERE id IN ('+ids(user_ids)+');'
 cleanup+='DELETE FROM organizations WHERE id IN ('+ids(office_ids)+');'
 cleanup+='DELETE FROM office_audit WHERE organization_id IN ('+ids(office_ids)+');COMMIT;'
 sql(cleanup)
 print('EXACT_QA_FIXTURES_REMOVED',flush=True)
