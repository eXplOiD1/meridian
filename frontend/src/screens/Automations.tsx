/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary */
import { useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatTime } from '../lib/format';
import { hrefOf } from '../lib/useHashRoute';
import { t } from '../i18n';
import type { Profile } from '../types';

interface Condition { kind: string; id: number; value: string; start: string; end: string; days: number[]; count: number }
interface Action { kind: string; id: number; ids: number[]; text: string }
interface Definition { name: string; enabled: boolean; event: string; source_id: number; cron: string; timezone: string; logic: string; conditions: Condition[]; actions: Action[]; cooldown_seconds: number }
interface Execution { id: number; at: string; status: string; detail: string; actions: Array<{ kind: string; id: number; run_id?: number; delivery_id?: number }> }
interface Rule extends Definition { id: number; next_at: string | null; last_at: string | null; executions: Execution[] }
interface Option { id: number; name: string }
interface Options { automations: Rule[]; jobs: Option[]; monitors: Option[]; channels: Option[] }
const EVENTS: Record<string,string> = { 'job.failed':'Job fehlgeschlagen','job.ok':'Job erfolgreich','job.timeout':'Job-Zeitlimit überschritten','job.aborted':'Job abgebrochen','job.recovery':'Job wieder erfolgreich','monitor.down':'Überwachung meldet Ausfall','monitor.up':'Überwachung wieder erreichbar',schedule:'Zeitplan' };
const KINDS: Record<string,string> = { job_status:'Zustand eines Jobs',job_failures:'Aufeinanderfolgende Job-Fehler',monitor_state:'Zustand einer Überwachung',time:'Uhrzeitfenster',weekday:'Wochentage' };
const STATES: Record<string,string> = { ok:'Erfolgreich',failed:'Fehlgeschlagen',timeout:'Zeitlimit überschritten',aborted:'Abgebrochen',queued:'Eingereiht',running:'Läuft',up:'Erreichbar',down:'Ausfall',waiting:'Wartet',paused:'Deaktiviert' };
const empty = (): Definition => ({ name:'',enabled:true,event:'job.failed',source_id:0,cron:'0 * * * *',timezone:Intl.DateTimeFormat().resolvedOptions().timeZone,logic:'all',conditions:[],actions:[{kind:'job',id:0,ids:[],text:''}],cooldown_seconds:60 });

function timezones(current: string): string[] {
  let list: string[];
  try { list = Intl.supportedValuesOf('timeZone'); } catch { list = ['Europe/Berlin']; }
  return [...new Set([...list,'UTC',current])].sort((a,b)=>a.localeCompare(b));
}

export function Automations({ profile }: { profile: Profile }) {
  const [data,setData]=useState<Options | null>(null);
  const [form,setForm]=useState<Definition>(empty);
  const [editing,setEditing]=useState<number | null>(null);
  const [password,setPassword]=useState('');
  const [busy,setBusy]=useState(false);
  const [error,setError]=useState<string | null>(null);
  const [notice,setNotice]=useState<string | null>(null);
  const [expanded,setExpanded]=useState<number | null>(null);
  const allowed=profile.roles.some(role=>role.categories===null&&role.permissions.includes('automations.manage'));
  async function load(){try{setData(await request<Options>('GET','/api/automations'));}catch(caught){setError(errorMessage(caught));}}
  useEffect(()=>{if(!allowed)return;void load();const timer=setInterval(()=>void load(),15000);return()=>clearInterval(timer);},[allowed]);
  const jobs=data?.jobs??[];const monitors=data?.monitors??[];const channels=data?.channels??[];
  function reset(){setForm(empty());setEditing(null);setPassword('');}
  function edit(rule: Rule){setEditing(rule.id);setPassword('');setNotice(null);setError(null);setForm({name:rule.name,enabled:rule.enabled,event:rule.event,source_id:rule.source_id,cron:rule.cron,timezone:rule.timezone,logic:rule.logic,conditions:rule.conditions,actions:rule.actions,cooldown_seconds:rule.cooldown_seconds});}
  async function save(event: React.FormEvent){
    event.preventDefault();setBusy(true);setError(null);setNotice(null);
    try{await request(editing===null?'POST':'PUT','/api/automations'+(editing===null?'':'/'+editing),{csrf:profile.csrf_token,body:{...form,...(form.enabled?{current_password:password}:{})}});reset();await load();setNotice(t('Automation gespeichert.'));}
    catch(caught){setError(caught instanceof ApiError && Object.keys(caught.fields).length ? Object.values(caught.fields).map(message=>t(message)).join(' ') : errorMessage(caught));}finally{setPassword('');setBusy(false);}
  }
  async function remove(rule: Rule){
    if(!window.confirm(t('Automation „{name}“ löschen?',{name:rule.name})))return;
    setBusy(true);setError(null);
    try{await request('DELETE','/api/automations/'+rule.id,{csrf:profile.csrf_token});if(editing===rule.id)reset();await load();}catch(caught){setError(errorMessage(caught));}finally{setBusy(false);}
  }
  async function test(rule: Rule){
    setBusy(true);setError(null);setNotice(null);
    try{const result=await request<{matched:boolean}>('POST','/api/automations/'+rule.id+'/test',{csrf:profile.csrf_token});setNotice(result.matched?t('Bedingungen aktuell erfüllt. Der Test hat keine Aktionen ausgeführt.'):t('Bedingungen aktuell nicht erfüllt. Der Test hat keine Aktionen ausgeführt.'));}
    catch(caught){setError(errorMessage(caught));}finally{setBusy(false);}
  }
  function condition(index:number, patch:Partial<Condition>){setForm(old=>({...old,conditions:old.conditions.map((entry,i)=>i===index?{...entry,...patch}:entry)}));}
  function action(index:number,patch:Partial<Action>){setForm(old=>({...old,actions:old.actions.map((entry,i)=>i===index?{...entry,...patch}:entry)}));}
  function select(options:Option[],value:number,onChange:(id:number)=>void,label:string){return <label className="field"><span className="field__label">{t(label)}</span><select className="input" value={value} onChange={event=>onChange(Number(event.target.value))} required><option value={0}>{t('Bitte wählen …')}</option>{options.map(option=><option value={option.id} key={option.id}>{option.name}</option>)}</select></label>;}
  if(!allowed)return <Alert tone="err">{t('Für Automationen fehlt dir das Recht.')}</Alert>;
  return <>
    {error&&<Alert tone="err">{error}</Alert>}{notice&&<Alert tone="ok">{notice}</Alert>}
    <section className="card"><h2>{t('Wenn … dann …')}</h2><p className="card__lead">{t('Automationen reagieren auf Jobs, Überwachungen oder einen Zeitplan. Sind die Bedingungen erfüllt, werden Jobs gestartet oder Nachrichten gesendet.')}</p>
      {!data&&<p>{t('Lädt …')}</p>}{data?.automations.length===0&&<p>{t('Noch keine Automationen.')}</p>}
      {data?.automations.map(rule=><article className="automation-rule" key={rule.id}>
        <div className="row"><strong>{rule.name}</strong><span className={'badge'+(!rule.enabled?' badge--muted':'')}>{t(rule.enabled?'Aktiv':'Deaktiviert')}</span></div>
        <p>{t(EVENTS[rule.event]??rule.event)}{rule.event==='schedule'?' · '+rule.cron+' · '+rule.timezone:' · '+((rule.event.startsWith('job.')?jobs:monitors).find(option=>option.id===rule.source_id)?.name??'#'+rule.source_id)}</p>
        <p className="hint">{t('Letzte Ausführung')}: {rule.last_at?formatTime(rule.last_at):'—'}{rule.next_at&&<> · {t('Nächster Termin')}: {formatTime(rule.next_at)}</>}</p>
        <div className="row"><button className="btn btn--ghost" disabled={busy} onClick={()=>edit(rule)}>{t('Bearbeiten')}</button><button className="btn btn--ghost" disabled={busy} onClick={()=>void test(rule)}>{t('Bedingungen prüfen')}</button><button className="btn btn--ghost" onClick={()=>setExpanded(expanded===rule.id?null:rule.id)} aria-expanded={expanded===rule.id}>{t('Protokoll')}</button><button className="btn btn--danger" disabled={busy} onClick={()=>void remove(rule)}>{t('Löschen')}</button></div>
        {expanded===rule.id&&<div className="table-wrap"><table className="table"><thead><tr><th>{t('Zeit')}</th><th>{t('Ergebnis')}</th><th>{t('Aktionen')}</th></tr></thead><tbody>{rule.executions.map(execution=><tr key={execution.id}><td>{formatTime(execution.at)}</td><td>{t(execution.status==='executed'?'Aktionen eingereiht.':execution.status==='skipped'?'Übersprungen':'Fehlgeschlagen')}<div className="hint">{t(execution.detail)}</div></td><td>{execution.actions.map((entry,index)=>entry.run_id?<div key={index}><a href={hrefOf('jobs/'+entry.id)}>{t('Job-Lauf')} #{entry.run_id}</a></div>:<div key={index}>{t('Benachrichtigung')} #{entry.delivery_id}</div>)}</td></tr>)}</tbody></table>{rule.executions.length===0&&<p>{t('Noch keine Ausführungen.')}</p>}</div>}
      </article>)}
    </section>
    <section className="card"><h2>{t(editing===null?'Automation anlegen':'Automation bearbeiten')}</h2><form className="form" onSubmit={event=>void save(event)}>
      <div className="grid-2 automation-identity"><label className="field"><span className="field__label">{t('Name')}</span><input className="input" value={form.name} maxLength={100} required onChange={event=>setForm({...form,name:event.target.value})}/></label><label className="check"><input type="checkbox" checked={form.enabled} onChange={event=>setForm({...form,enabled:event.target.checked})}/>{t('Automation ist aktiv')}</label></div>
      <h3>{t('Wenn')}</h3><div className="grid-2"><label className="field"><span className="field__label">{t('Auslöser')}</span><select className="input" value={form.event} onChange={event=>setForm({...form,event:event.target.value,source_id:0})}>{Object.entries(EVENTS).map(([key,label])=><option value={key} key={key}>{t(label)}</option>)}</select></label>{form.event!=='schedule'&&select(form.event.startsWith('job.')?jobs:monitors,form.source_id,id=>setForm({...form,source_id:id}),form.event.startsWith('job.')?'Auslösender Job':'Auslösende Überwachung')}</div>
      {form.event==='schedule'&&<div className="grid-2"><label className="field"><span className="field__label">{t('Zeitplan')}</span><input className="input input--mono" value={form.cron} required onChange={event=>setForm({...form,cron:event.target.value})}/><span className="hint">{t('Minute Stunde Tag Monat Wochentag, z. B. 0 8 * * 1-5 für werktags um 08:00.')}</span></label><label className="field"><span className="field__label">{t('Zeitzone')}</span><select className="input" value={form.timezone} required onChange={event=>setForm({...form,timezone:event.target.value})}>{timezones(form.timezone).map(zone=><option key={zone} value={zone}>{zone}</option>)}</select></label></div>}
      <h3>{t('Und wenn')}</h3><label className="field"><span className="field__label">{t('Bedingungen verknüpfen')}</span><select className="input" value={form.logic} onChange={event=>setForm({...form,logic:event.target.value})}><option value="all">{t('Alle Bedingungen müssen erfüllt sein')}</option><option value="any">{t('Mindestens eine Bedingung muss erfüllt sein')}</option></select></label>
      {form.conditions.length===0&&<p className="hint">{t('Ohne zusätzliche Bedingungen genügt der Auslöser.')}</p>}
      {form.conditions.map((entry,index)=><fieldset className="automation-step" key={index}><legend>{t('Bedingung')} {index+1}</legend><div className="grid-2"><label className="field"><span className="field__label">{t('Art')}</span><select className="input" value={entry.kind} onChange={event=>condition(index,{kind:event.target.value,id:0,value:event.target.value==='monitor_state'?'up':'ok'})}>{Object.entries(KINDS).map(([kind,label])=><option key={kind} value={kind}>{t(label)}</option>)}</select></label>
        {['job_status','job_failures','monitor_state'].includes(entry.kind)&&select(entry.kind==='monitor_state'?monitors:jobs,entry.id,id=>condition(index,{id}),entry.kind==='monitor_state'?'Überwachung':'Job')}
        {['job_status','monitor_state'].includes(entry.kind)&&<label className="field"><span className="field__label">{t('Zustand')}</span><select className="input" value={entry.value} onChange={event=>condition(index,{value:event.target.value})}>{(entry.kind==='monitor_state'?['up','down','waiting','paused']:['ok','failed','timeout','aborted','queued','running']).map(state=><option value={state} key={state}>{t(STATES[state]??state)}</option>)}</select></label>}
        {entry.kind==='job_failures'&&<label className="field"><span className="field__label">{t('Mindestens so viele Fehler in Folge')}</span><input className="input" type="number" min={1} max={100} value={entry.count} onChange={event=>condition(index,{count:Number(event.target.value)})}/></label>}
        {entry.kind==='time'&&<><label className="field"><span className="field__label">{t('Von')}</span><input type="time" className="input" value={entry.start} required onChange={event=>condition(index,{start:event.target.value})}/></label><label className="field"><span className="field__label">{t('Bis (ausschließlich)')}</span><input type="time" className="input" value={entry.end} required onChange={event=>condition(index,{end:event.target.value})}/></label></>}
        {entry.kind==='weekday'&&<div className="row">{['Mo','Di','Mi','Do','Fr','Sa','So'].map((day,i)=><label className="check" key={day}><input type="checkbox" checked={entry.days.includes(i+1)} onChange={event=>condition(index,{days:event.target.checked?[...entry.days,i+1]:entry.days.filter(value=>value!==i+1)})}/>{t(day)}</label>)}</div>}
      </div><button type="button" className="btn btn--ghost" onClick={()=>setForm({...form,conditions:form.conditions.filter((_,i)=>i!==index)})}>{t('Bedingung entfernen')}</button></fieldset>)}
      <button type="button" className="btn btn--ghost" disabled={form.conditions.length>=10} onClick={()=>setForm({...form,conditions:[...form.conditions,{kind:'job_status',id:0,value:'ok',start:'09:00',end:'18:00',days:[1,2,3,4,5],count:3}]})}>{t('Bedingung hinzufügen')}</button>
      <label className="field"><span className="field__label">{t('Zeitzone für Bedingungen')}</span><select className="input" value={form.timezone} required onChange={event=>setForm({...form,timezone:event.target.value})}>{timezones(form.timezone).map(zone=><option key={zone} value={zone}>{zone}</option>)}</select></label>
      <h3>{t('Dann')}</h3>{form.actions.map((entry,index)=><fieldset className="automation-step" key={index}><legend>{t('Aktion')} {index+1}</legend><div className="grid-2"><label className="field"><span className="field__label">{t('Art')}</span><select className="input" value={entry.kind} onChange={event=>action(index,{kind:event.target.value,id:0,ids:[]})}><option value="job">{t('Job starten')}</option><option value="notify">{t('Benachrichtigung senden')}</option></select></label>{entry.kind==='job'&&select(jobs,entry.id,id=>action(index,{id}),'Ziel-Job')}</div>
        {entry.kind==='notify'&&<><label className="field"><span className="field__label">{t('Nachricht')}</span><textarea className="input input--area" value={entry.text} maxLength={1000} required onChange={event=>action(index,{text:event.target.value})}/></label><fieldset><legend>{t('Kanäle')}</legend>{channels.map(channel=><label className="check" key={channel.id}><input type="checkbox" checked={entry.ids.includes(channel.id)} onChange={event=>action(index,{ids:event.target.checked?[...entry.ids,channel.id]:entry.ids.filter(id=>id!==channel.id)})}/>{channel.name}</label>)}{channels.length===0&&<p>{t('Zuerst einen Benachrichtigungskanal in den Einstellungen anlegen.')}</p>}</fieldset></>}
        {form.actions.length>1&&<button type="button" className="btn btn--ghost" onClick={()=>setForm({...form,actions:form.actions.filter((_,i)=>i!==index)})}>{t('Aktion entfernen')}</button>}
      </fieldset>)}
      <button type="button" className="btn btn--ghost" disabled={form.actions.length>=5} onClick={()=>setForm({...form,actions:[...form.actions,{kind:'job',id:0,ids:[],text:''}]})}>{t('Aktion hinzufügen')}</button>
      <label className="field"><span className="field__label">{t('Mindestabstand zwischen Ausführungen (Sekunden)')}</span><input className="input" type="number" min={0} max={86400} value={form.cooldown_seconds} onChange={event=>setForm({...form,cooldown_seconds:Number(event.target.value)})}/></label>
      {form.enabled&&<label className="field"><span className="field__label">{t('Aktuelles Passwort')}</span><input className="input" type="password" value={password} required autoComplete="current-password" onChange={event=>setPassword(event.target.value)}/><span className="hint">{t('Aktive Automationen werden mit deinem Passwort bestätigt. Bei jedem Auslösen werden deine Rechte erneut geprüft.')}</span></label>}
      <p className="hint">{t('Geplante Jobs und bereits eingereihte Aktionen laufen ohne weitere Passwortabfrage. Bedingungen prüfen führt keine Aktionen aus.')}</p>
      <div className="row"><button className="btn btn--solid" type="submit" disabled={busy}>{t(busy?'Speichert …':'Speichern')}</button>{editing!==null&&<button className="btn btn--ghost" type="button" onClick={reset}>{t('Abbrechen')}</button>}</div>
    </form></section>
  </>;
}
