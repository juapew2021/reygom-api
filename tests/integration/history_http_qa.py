import subprocess,json,urllib.request,urllib.error,uuid,secrets,time,hashlib
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
office_ids=[];user_ids=[];property_ids=[];lead_ids=[];token_hashes=[]
prefix='qahistory'+uuid.uuid4().hex[:8]
private_value='QA_PRIVATE_VALUE_'+uuid.uuid4().hex
try:
 expect('GET','/audit',status=401,label='Anonymous history denied')
 options=expect('GET','/audit/options',auth=token,label='Super admin history options')
 check(bool(options['coverage_since']),'Coverage start recorded without fabricated past events')
 offices=[];people=[]
 for suffix,country in [('a','ES'),('b','CO')]:
  o=expect('POST','/offices',{'name':'QA Historial '+suffix,'slug':prefix+suffix,'country_code':country},token,status=201,label='Create history office '+suffix);office_ids.append(o['id']);offices.append(o)
  for role in ['admin','agent']:
   username=prefix+suffix+role;password=secrets.token_urlsafe(24)
   u=expect('POST','/office-users',{'username':username,'password':password,'role':role},token,o['id'],201,'Create history '+suffix+role);user_ids.append(u['id'])
   login=expect('POST','/login',{'username':username,'password':password},label='Login history '+suffix+role)
   people.append({**u,'password':password,'token':login['token']})
 a,b=offices;aa,x,ab,y=people
 expect('GET','/audit',auth=aa['token'],status=403,label='Office admin cannot see global history')
 expect('GET','/audit/options',auth=x['token'],status=403,label='Agent cannot see global user options')
 expect('POST','/audit',{},token,status=405,label='Audit API cannot create manual events')
 expect('DELETE','/audit',auth=token,status=405,label='Audit API cannot delete events')
 expect('PUT','/audit',{},token,status=405,label='Audit API cannot edit events')
 def prop(person,name):
  row=expect('POST','/properties',{'title':name,'description':private_value,'price':1000,'city':'Vic','province':'Barcelona','country_code':'ES','status':'Oculto','operation_type':'Alquiler','property_type':'Piso'},person['token'],status=201,label='Create audited property '+name)
  property_ids.append(row['id']);return row['id']
 pa=prop(x,'QA History A');pb=prop(y,'QA History B')
 reference=sql("SELECT reference_id FROM properties WHERE id='%s';"%pa)
 expect('PUT','/properties/'+pa,{'title':'QA History A edited','price':1050,'description':private_value},x['token'],label='Update audited property')
 la=expect('POST','/leads',{'full_name':'QA History contact','contact_info':{'phone':private_value},'property_interest_id':pa},x['token'],status=201,label='Create audited contact')['id'];lead_ids.append(la)
 image=expect('POST','/properties/'+pa+'/images',{'image_url':'https://example.invalid/qa-history.jpg'},x['token'],status=201,label='Create audited photo')
 expect('PUT','/office-users/'+x['id'],{'role':'agent','is_active':False},aa['token'],label='Admin deactivates agent with audit')
 expect('GET','/properties',auth=x['token'],status=401,label='Deactivated agent remains blocked')
 expect('PUT','/properties/'+pa,{'title':'QA History A edited','price':1060,'description':private_value},aa['token'],label='Administrator can maintain inactive agents property')
 guard_sql="BEGIN; SELECT set_config('reygom.user_id','%s',true),set_config('reygom.organization_id','%s',true); SET LOCAL ROLE reygom_office_runtime; DO $$ BEGIN BEGIN UPDATE properties SET agent_id='%s' WHERE id='%s'; RAISE EXCEPTION 'TEST_UNSAFE_ASSIGNMENT'; EXCEPTION WHEN raise_exception THEN IF SQLERRM <> 'Agent outside office' THEN RAISE; END IF; END; BEGIN INSERT INTO properties(reference_id,agent_id,title,price,operation_type,property_type) VALUES('QA%s','%s','QA forbidden inactive assignment',1,'Alquiler','Piso'); RAISE EXCEPTION 'TEST_UNSAFE_ASSIGNMENT'; EXCEPTION WHEN raise_exception THEN IF SQLERRM <> 'Agent outside office' THEN RAISE; END IF; END; END $$;ROLLBACK;"%(aa['id'],a['id'],y['id'],pa,uuid.uuid4().hex,x['id'])
 sql(guard_sql);check(True,'Foreign and new inactive assignments remain blocked')
 history=expect('GET','/audit?user='+x['id'],auth=token,label='History includes actor and affected user')
 rows=history['rows'];actions={r['action'] for r in rows}
 check({'session.login','property.created','property.updated','lead.created','image.created','user.created','user.updated'}<=actions,'All expected event categories recorded')
 check(all(r['actor_id']==x['id'] or (r['subject_type']=='user' and r['subject_id']==x['id']) for r in rows),'User filter includes only acting or affected user')
 changed=next(r for r in rows if r['action']=='user.updated' and r['subject_id']==x['id'])
 check(changed['actor_id']==aa['id'] and changed['metadata']['changes']['is_active']=={'before':True,'after':False},'Administrator attribution and activation change retained')
 property_event=next(r for r in rows if r['action']=='property.updated' and r['subject_id']==pa and isinstance(r['metadata'].get('changes'),dict) and 'price' in r['metadata']['changes'])
 check(property_event['actor_id']==x['id'] and property_event['subject_ref']==reference,'Property actor and permanent reference retained')
 check(property_event['metadata']['changes']['price']=={'before':1000,'after':1050},'Price before and after recorded')
 encoded=json.dumps(rows)
 check(private_value not in encoded and all(p['password'] not in encoded and p['token'] not in encoded for p in people),'No private contact values, descriptions, passwords or tokens in history')
 scoped=expect('GET','/audit?office='+a['id'],auth=token,label='Filter by office')
 check(scoped['rows'] and all(r['organization_id']==a['id'] for r in scoped['rows']),'Office filter excludes other companies')
 filtered=expect('GET','/audit?office='+a['id']+'&action=property.updated',auth=token,label='Filter by action')
 check(filtered['rows'] and all(r['action']=='property.updated' for r in filtered['rows']),'Action filter exact')
 expect('GET','/audit?user=invalid',auth=token,status=422,label='Invalid user filter rejected')
 expect('GET','/audit?page=0',auth=token,status=422,label='Invalid page rejected')
 expect('GET','/audit?from=invalid',auth=token,status=422,label='Invalid date rejected')
 expect('GET','/audit?from=2026-02-31T00%3A00%3A00Z',auth=token,status=422,label='Impossible calendar date rejected')
 future=expect('GET','/audit?from=2035-01-01T00%3A00%3A00Z',auth=token,label='Date range filter')
 check(future['total']==0,'Future date range returns no history')
 before=sql("SELECT count(*) FROM office_audit WHERE subject_id='%s' AND action='property.updated';"%pa)
 sql("BEGIN; SELECT set_config('reygom.user_id','%s',true),set_config('reygom.organization_id','%s',true); UPDATE properties SET price=1099 WHERE id='%s'; ROLLBACK;"%(aa['id'],a['id'],pa))
 check(sql("SELECT count(*) FROM office_audit WHERE subject_id='%s' AND action='property.updated';"%pa)==before,'Rolled back mutations leave no false audit event')
 expect('PUT','/offices/'+b['id'],{'name':b['name'],'country_code':'CO','status':'inactive'},token,label='Suspend office before access test')
 expect('POST','/login',{'username':y['username'],'password':y['password']},status=403,label='Suspended office login rejected and recorded')
 expect('PUT','/offices/'+b['id'],{'name':b['name'],'country_code':'CO','status':'active'},token,label='Reactivate audited office')
 expect('PUT','/office-users/'+y['id'],{'role':'admin','is_active':True},token,b['id'],label='Audited role change')
 role_history=expect('GET','/audit?user='+y['id']+'&action=user.updated',auth=token,label='Role change in affected user history')
 check(any(r['metadata'].get('changes',{}).get('role')=={'before':'agent','after':'admin'} for r in role_history['rows']),'Role before and after recorded')
 y['token']=expect('POST','/login',{'username':y['username'],'password':y['password']},label='Login after role change')['token']
 expect('POST','/login',{'username':y['username'],'password':'wrong-test-password'},status=401,label='Rejected login recorded')
 failed=expect('GET','/audit?user='+y['id']+'&action=session.login_failed',auth=token,label='Rejected login in affected user history')
 check(failed['rows'][0]['actor_id'] is None and failed['rows'][0]['subject_id']==y['id'],'Failed login does not falsely attribute identity')
 token_hashes.append(hashlib.sha256(y['token'].encode()).hexdigest())
 expect('POST','/logout',auth=y['token'],label='Logout recorded and token revoked')
 expect('GET','/properties',auth=y['token'],status=401,label='Logged out token no longer works')
 new=expect('POST','/login',{'username':y['username'],'password':y['password']},label='Fresh login after logout')
 check(new['token']!=y['token'],'New login has a unique session token')
 expect('GET','/properties',auth=new['token'],label='New session works after logout')
 logout=expect('GET','/audit?user='+y['id']+'&action=session.logout',auth=token,label='Logout in user history');check(logout['rows'][0]['actor_id']==y['id'],'Logout correctly attributed')
 # Seed only synthetic pagination events in a synthetic office.
 sql("INSERT INTO office_audit(actor_id,actor_name,organization_id,office_name,action,subject_id,subject_type,subject_ref,metadata,source) SELECT '%s','%s','%s','QA Historial a','property.updated','%s','property','%s','{}','application' FROM generate_series(1,60);"%(x['id'],x['username'],a['id'],pa,reference))
 first=expect('GET','/audit?user='+x['id']+'&page=1',auth=token,label='First history page')
 second=expect('GET','/audit?user='+x['id']+'&page=2',auth=token,label='Second history page')
 check(len(first['rows'])==50 and second['rows'] and not ({r['id'] for r in first['rows']}&{r['id'] for r in second['rows']}),'Pagination bounded and stable without duplicate rows')
 protected_id=first['rows'][0]['id']
 attempt=subprocess.run(kub+['exec','-i','deployment/reygom-db','--','sh','-lc','psql -v ON_ERROR_STOP=1 -At -U "$POSTGRES_USER" -d "$POSTGRES_DB"'],input="DELETE FROM office_audit WHERE id='%s';"%protected_id,text=True,capture_output=True)
 check(attempt.returncode!=0 and 'cannot be modified or deleted' in attempt.stderr,'Database audit deletion blocked without explicit maintenance context')
 expect('DELETE','/property-images/'+image['id'],auth=aa['token'],label='Audited image deletion')
 expect('DELETE','/leads/'+la,auth=aa['token'],label='Audited contact deletion')
 expect('DELETE','/properties/'+pa,auth=aa['token'],label='Audited property deletion')
 removed=expect('GET','/audit?user='+aa['id']+'&action=property.deleted',auth=token,label='Deleted property history remains')
 check(any(r['subject_id']==pa and r['subject_ref']==reference for r in removed['rows']),'Deleted resource reference retained in history')
 sql("BEGIN;SELECT set_config('reygom.user_id','%s',true),set_config('reygom.organization_id','%s',true);DELETE FROM users WHERE id='%s';COMMIT;"%(aa['id'],a['id'],x['id']))
 archived=expect('GET','/audit?user='+x['id'],auth=token,label='Deleted account history remains')
 check(any(r['actor_id']==x['id'] and r['actor_name']==x['username'] for r in archived['rows']),'Deleted account name snapshot retained')
 options=expect('GET','/audit/options',auth=token,label='Archived user available as history filter');check(any(u['id']==x['id'] for u in options['users']),'Archived user filter retained')
 print('HISTORY_HTTP_QA_PASSED',counter,flush=True)
finally:
 def ids(values):return ','.join("'%s'"%str(uuid.UUID(v)) for v in values) or 'NULL'
 cleanup="BEGIN; SET LOCAL reygom.audit_maintenance='on';"
 cleanup+='DELETE FROM property_images WHERE property_id IN ('+ids(property_ids)+');'
 cleanup+='DELETE FROM leads WHERE id IN ('+ids(lead_ids)+');'
 cleanup+='DELETE FROM properties WHERE id IN ('+ids(property_ids)+');'
 cleanup+='DELETE FROM users WHERE id IN ('+ids(user_ids)+');'
 cleanup+='DELETE FROM organizations WHERE id IN ('+ids(office_ids)+');'
 cleanup+='DELETE FROM office_audit WHERE organization_id IN ('+ids(office_ids)+');'
 if token_hashes:cleanup+='DELETE FROM revoked_sessions WHERE token_hash IN ('+','.join("'%s'"%h for h in token_hashes)+');'
 cleanup+='COMMIT;';sql(cleanup);print('EXACT_HISTORY_FIXTURES_REMOVED',flush=True)
