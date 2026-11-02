// Interface administrateur : comptes rendus, rapport client, listes.
const A = { L:null, tab:"rapports", f:{ from:"", to:"", zone:"", marque:"", animatrice:"" }, rows:[], detail:null, magZone:"France",
  rc:{ from:"", to:"", zone:"", marques:[], to_mail:"", analyse:true, ia:true, photos:[], sel:null, joindre:true, rlist:[], rsel:null, jpdf:true, jxlsx:true } };
/** « Julie HOARAU » : prénom en minuscules (initiale en capitale), nom en majuscules. */
// « HOARAU Julie » : NOM en majuscules d'abord, puis le prénom (initiale en capitale).
const nm = a => { const p = String(a.prenom || "").toLowerCase().replace(/(^|[\s-])\p{L}/gu, x => x.toUpperCase()); return (String(a.nom || "").toUpperCase() + " " + p).trim(); };
const byNom = (x, y) => String(x.nom).localeCompare(String(y.nom), "fr", {sensitivity:"base"}) || String(x.prenom).localeCompare(String(y.prenom), "fr", {sensitivity:"base"});
// Deux espaces : Reporting (selon les droits) et Administration (direction seulement).
const ALL_TABS = [["rapports","Comptes rendus"],["client","Rapports d'animation"],["evts","Rapports événementiels"],["merch","Rapports merchandising"],["objectifs","Objectifs"],["photos","Photothèque"],["animatrices","Intervenants","tout"],["documents","Documents","gestion"],["marques","Marques","tout"],["formations","Formations","tout"],["magasins","Points d'intervention","tout"],["envois","Envois"],["clients","Espace marques","tout"],["admins","Administrateurs","gestion"],["gestion","Administration","gestion"]];
const ADM_SUBS = [["operations","Opérations"],["devis","Devis"],["planning","Planning"],["poste","Prise de poste"],["contrats","Contrats"],["releves","Relevés d'heures"],["paye","Paye"],["ndf","Notes de frais et factures"],["rup","Registre du personnel"],["marges","Marges"],["factures","Factures"],["clients","Clients"],["animatrices","Intervenants"],["documents","Documents"],["formations","Formations"],["marques","Marques"],["magasins","Points d'intervention"],["reglages","Réglages"],["maintenance","Maintenance"],["admins","Administrateurs"]];
// Onglets rangés dans Administration pour la direction (les autres administrateurs qui y ont droit les gardent dans Reporting).
const ADM_TABS = ["admins", "animatrices", "documents", "formations", "marques", "magasins"];
const TABS = () => ALL_TABS.filter(t => (!t[2] || (t[2] === "gestion" ? !!(A.L.moi || {}).gestion : can(t[2]))));
const estAdm = t => t === "gestion" || t === "admins" || (ADM_TABS.includes(t) && !!((A.L || {}).moi || {}).gestion);
function ym(d){ return d.toISOString().slice(0,10); }
(function initDates(){ const n=new Date(); const f=new Date(n.getFullYear(), n.getMonth(), 1);
  A.f.from = A.rc.from = ym(new Date(f.getTime()-f.getTimezoneOffset()*6e4)); A.f.to = A.rc.to = ym(new Date(n.getTime()-n.getTimezoneOffset()*6e4)); })();

$("#logout").onclick = async () => { await api("logout", {}); if (typeof AMAYA_DEMO !== "undefined" && AMAYA_DEMO) location.href = "demo.php"; else location.reload(); };

async function boot(){
  try {
    const s = await api("session");
    if (!s.admin && s.inscription) return vInscription(s.inscription);
    if (!s.admin) return vLogin();
    $("#logout").hidden = false;
    await reloadLists();
    if (A.L.moi && A.L.moi.id && !$("#myacc")) { const b = document.createElement("button"); b.id = "myacc"; b.textContent = A.L.moi.nom; b.title = "Mon compte : mot de passe et double authentification"; b.onclick = myAccount; $("#logout").before(b); }
    // la direction arrivent toujours sur le tableau de bord (Administration) ; les autres sur les comptes rendus.
    if ((A.L.moi || {}).gestion) A.tab = "gestion"; else if (!TABS().some(t => t[0] === A.tab)) A.tab = "rapports";
    A.rc.to_mail = A.L.mail_to;
    A.rc.ia = A.L.ia;
    go(A.tab);
  } catch(e) { $("#view").innerHTML = `<div class="card"><b>Connexion impossible</b><div class="muted">${esc(e.message)}</div></div>`; }
}
async function reloadLists(){ A.L = await api("admin_lists"); }
function vLogin(){
  if (typeof AMAYA_DEMO !== "undefined" && AMAYA_DEMO) { location.href = "demo.php"; return; }
  $("#logout").hidden = true;
  $("#view").innerHTML = `<div class="card login accent"><h1>Administration</h1><label>E-mail<input id="em" type="email" autocomplete="username" placeholder="votre@e-mail.com"></label><label>Mot de passe<input id="pw" type="password" autocomplete="current-password"></label><button class="primary" id="go">Se connecter</button></div>`;
  const g = async () => { try { const r = await api("login_admin", {email:$("#em").value.trim(), password:$("#pw").value}); if (r.code) return vLoginCode(); if (r.inscription) return vInscription(r); boot(); } catch(e) { toast(e.message); } };
  $("#go").onclick = g; $("#pw").onkeydown = e => { if (e.key === "Enter") g(); };
}
// 2e étape de connexion : code de l'appli d'authentification (ou code de secours).
function vLoginCode(){
  $("#view").innerHTML = `<div class="card login accent"><h1>Code de sécurité</h1><div class="muted">Ouvrez votre appli d'authentification (Google Authenticator, Microsoft Authenticator…) et saisissez le code à 6 chiffres affiché pour ${esc(AG)}.</div>
    <label>Code<input id="tfc" inputmode="numeric" autocomplete="one-time-code" maxlength="9" class="pin" placeholder="123456"></label><button class="primary" id="tfgo">Valider</button>
    <div class="hint">Téléphone perdu ? Saisissez l'un de vos codes de secours (ex. 1A2B-3C4D).</div></div>`;
  const g = async () => { try { const r = await api("login_totp", {code:$("#tfc").value}); if (r.inscription) return vInscription(r); boot(); } catch(e) { toast(e.message); if (e.status === 429 || /expirée|recommencez/i.test(e.message)) vLogin(); } };
  $("#tfgo").onclick = g; $("#tfc").onkeydown = e => { if (e.key === "Enter") g(); }; $("#tfc").focus();
}
// Activation obligatoire avant tout accès : nouveau mot de passe personnel (après le mot de passe de secours) et/ou double authentification.
async function vInscription(o){
  $("#logout").hidden = true; let P = {secret:"", uri:""};
  if (o.tf) { try { P = await api("ins_preparer"); } catch(e) { toast(e.message); return vLogin(); } }
  $("#view").innerHTML = `<div class="card login accent"><h1>Sécurisez votre accès</h1>
    <div class="muted">${o.tf ? "La double authentification est obligatoire pour l'administration : en plus du mot de passe, un code à 6 chiffres de votre téléphone sera demandé à chaque connexion." : "Vous vous êtes connecté(e) avec le mot de passe de secours : choisissez votre mot de passe personnel."}</div>
    ${o.mdp ? `<label>Nouveau mot de passe personnel <span class="hint">· 12 caractères au moins, différent du mot de passe de secours</span><input id="in-pw" type="password" autocomplete="new-password"></label>
      <label>Confirmez<input id="in-pw2" type="password" autocomplete="new-password"></label>` : ""}
    ${o.tf ? `<div>1. Installez une appli d'authentification (Google Authenticator, Microsoft Authenticator…) sur votre téléphone.<br>2. Scannez ce QR code avec l'appli.</div>
      <div id="in-qr" style="align-self:center;background:#fff;padding:10px;border-radius:12px"></div><div class="hint">Sans appareil photo : saisissez la clé <b>${esc(P.secret)}</b></div>
      <label>3. Code affiché par l'appli<input id="in-c" inputmode="numeric" maxlength="6" class="pin" placeholder="123456" autocomplete="one-time-code"></label>` : ""}
    <button class="primary" id="in-go">Terminer</button><button class="ghost" id="in-x">Annuler</button></div>`;
  if (o.tf) try { const q = qrcode(0, "M"); q.addData(P.uri); q.make(); $("#in-qr").innerHTML = q.createSvgTag({cellSize:5, margin:0}); } catch(x) { $("#in-qr").remove(); }
  $("#in-x").onclick = async () => { try { await api("logout", {}); } catch(e) {} vLogin(); };
  $("#in-go").onclick = async () => { const b = $("#in-go"); b.disabled = true;
    try { const r = await api("ins_finir", {password:$("#in-pw") ? $("#in-pw").value : "", confirm:$("#in-pw2") ? $("#in-pw2").value : "", code:$("#in-c") ? $("#in-c").value : ""});
      if (r.secours && r.secours.length) { $("#view").innerHTML = `<div class="card login accent"><h1>Codes de secours</h1><div><span class="pill p-ok">Double authentification activée</span></div>
          <div>Chacun de ces codes ne sert qu'une fois, si vous perdez votre téléphone. Notez-les et gardez-les en lieu sûr : ils ne seront plus affichés.</div>
          <div class="tf-codes">${r.secours.map(c => `<code>${esc(c)}</code>`).join("")}</div><button class="primary" id="in-ok">J'ai noté mes codes, continuer</button></div>`;
        $("#in-ok").onclick = () => boot(); }
      else boot(); }
    catch(e) { toast(e.message); b.disabled = false; if (e.status === 401) vLogin(); } };
}
// Tableau de bord : seuls les deux grands boutons Reporting et Administration au-dessus.
const surDash = () => A.tab === "gestion" && typeof G !== "undefined" && G.sub === "tableau" && !G.ed;
function shell(inner){
  const dash = surDash(), adm = estAdm(A.tab) && !dash, peut = !!(A.L.moi || {}).gestion;
  const sec = peut ? `<div class="secs"><button data-sec="rep" aria-current="${!adm && !dash}"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>Reporting</button><button data-sec="adm" aria-current="${adm}"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M8 13h8"/></svg>Administration</button></div>` : "";
  const row = adm ? ADM_SUBS.map(([k, l]) => { const on = ADM_TABS.includes(k) ? A.tab === k : A.tab === "gestion" && (typeof G !== "undefined" && G.sub === k);
      return `<button data-asub="${k}" aria-current="${on ? "page" : "false"}">${l}</button>`; }).join("")
    : TABS().filter(t => !estAdm(t[0])).map(([k,l]) => `<button data-tab="${k}" aria-current="${A.tab===k?"page":"false"}">${l}</button>`).join("");
  if (dash) return `${sec}${inner}`;
  return `${sec}${peut ? `<button class="dash-back" data-dash>‹ Tableau de bord</button>` : ""}<div class="tabs">${row}</div>${inner}`;
}
function bindShell(){
  document.querySelectorAll("[data-tab]").forEach(b => b.onclick = () => go(b.dataset.tab));
  document.querySelectorAll("[data-sec]").forEach(b => b.onclick = () => { if (b.dataset.sec === "rep") return go(A.lastRep || "rapports");
    if (A.lastAdm && A.lastAdm !== "gestion") return go(A.lastAdm); G.sub = A.lastSub || "planning"; G.ed = null; go("gestion"); });
  document.querySelectorAll("[data-dash]").forEach(b => b.onclick = toDash);
  document.querySelectorAll("[data-asub]").forEach(b => b.onclick = () => { const k = b.dataset.asub; if (ADM_TABS.includes(k)) return go(k); G.sub = k; G.ed = null; G.cli = null; G.mail = null; go("gestion"); });
}
function toDash(){ if (!(A.L.moi || {}).gestion) return go("rapports"); G.sub = "tableau"; G.ed = null; go("gestion"); }
async function go(tab){
  A.tab = tab;
  try {
    if (tab === "rapports") { if (A.crt === "evt") await loadEvts(); else if (A.crt === "mc") await loadMc(); else await loadRows(); }
    if (tab === "merch") await loadMcRap();
    if (tab === "marques") await loadMcRefs();
    if (tab === "evts") await loadEvtRap();
    if (tab === "formations") { const r = await api("formations", null, "admin=1"); A.forma = r.formations; A.flim = r.limite; }
    if (tab === "admins") await loadAdmins();
    if (tab === "photos" && !PH.keep) await loadPhotos(); PH.keep = false;
    if (tab === "clients") await loadComptes();
    if (tab === "objectifs") A.obj = (await api("objectifs_anim")).objectifs;
    if (tab === "gestion") await loadGestion();
    if (tab === "documents") await loadDocsAdm();
    $("#view").innerHTML = shell(({rapports:() => crTog() + (A.crt === "evt" ? vEvts() : A.crt === "mc" ? vMc() : vRapports() + mqCard()), evts:vEvtRap, merch:vMcRap, client:vClient, objectifs:vObj, photos:vPhotos, clients:vComptes, animatrices:vAnim, marques:vMarques, formations:vForma, magasins:vMag, envois:vEnvois, documents:vDocsAdm, admins:vAdmins, gestion:vGestion})[tab]());
    bindShell(); if (!surDash()) { if (estAdm(tab)) { A.lastAdm = tab; if (tab === "gestion") A.lastSub = G.sub; } else A.lastRep = tab; }
    ({rapports:() => { bCrTog(); if (A.crt === "evt") bEvts(); else if (A.crt === "mc") bMc(); else { bRapports(); mqBind(); } }, evts:bEvtRap, merch:bMcRap, client:bClient, objectifs:bObj, photos:bPhotos, clients:bComptes, animatrices:bAnim, marques:bMarques, formations:bForma, magasins:bMag, envois:bListes, documents:bDocsAdm, admins:bAdmins, gestion:bGestion})[tab]();
    if (typeof focusInject === "function") focusInject();
  } catch(e) { if (e.status === 401) return vLogin(); toast(e.message); }
}
// Filtre géographique : toute la France, un secteur (groupe de départements) ou un département où l'agence a des points d'intervention.
const secteurOpts = sel => { const deps = [...new Set((A.L.magasins || []).map(m => m.dep).filter(Boolean))].sort(), D = A.L.departements || {};
  return `<option value="">Toute la France</option>` + ((A.L.secteurs || []).length ? `<optgroup label="Secteurs">${A.L.secteurs.map(s => `<option value="s:${esc(s.nom)}" ${sel === "s:" + s.nom ? "selected" : ""}>${esc(s.nom)} (${s.deps.join(", ")})</option>`).join("")}</optgroup>` : "")
    + (deps.length ? `<optgroup label="Départements">${deps.map(d => `<option value="d:${d}" ${sel === "d:" + d ? "selected" : ""}>${esc(D[d] || d)} (${d})</option>`).join("")}</optgroup>` : ""); };
const zoneOpts = (sel, all) => (all ? `<option value="">Toutes zones</option>` : "") + A.L.zones.map(z => `<option ${sel===z?"selected":""}>${z}</option>`).join("");

/* ---------- Comptes rendus ---------- */
async function loadRows(){ A.rows = (await api("rapports", null, new URLSearchParams(A.f).toString())).rapports; }
function vRapports(){
  const f = A.f;
  return `<div class="card"><h2>Filtrer</h2><div class="row3">
      <label>Du<input type="date" id="f-from" value="${f.from}"></label><label>Au<input type="date" id="f-to" value="${f.to}"></label>
      <label>Secteur<select id="f-zone">${secteurOpts(f.zone)}</select></label>
      <label>Marque<select id="f-marque"><option value="">Toutes</option>${A.L.marques.map(m => `<option value="${m.id}" ${String(f.marque)===String(m.id)?"selected":""}>${esc(m.nom)}</option>`).join("")}</select></label>
      <label>Intervenant(e)<select id="f-anim"><option value="">Toutes</option>${A.L.animatrices.map(a => `<option value="${a.id}" ${String(f.animatrice)===String(a.id)?"selected":""}>${esc(nm(a))}</option>`).join("")}</select></label>
      <label>Validation<select id="f-statut"><option value="">Tous</option><option value="avalider" ${f.statut==="avalider"?"selected":""}>À valider</option><option value="valide" ${f.statut==="valide"?"selected":""}>Validés</option></select></label></div>
      <div class="btns"><button class="primary" id="f-go">Afficher</button></div></div>
    ${(() => { const n = new Set(A.rows.filter(r => !r.valide_le).map(r => r.id)).size; return n && can("rapports") ? `<div class="card val-bar"><div><b>${n} compte${n > 1 ? "s" : ""} rendu${n > 1 ? "s" : ""} à valider</b> sur cette sélection
      <div class="muted">Relisez (cliquez sur une ligne pour corriger et choisir les photos), cochez puis validez : les marques ne voient que les comptes rendus validés.</div></div>
      <div class="btns" style="margin:0"><button class="ghost" id="val-all">Cocher tous les « à valider »</button><button class="primary" id="val-go" disabled>Valider la sélection</button></div></div>` : ""; })()}
    <div class="card tablebox"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <h2>${A.rows.length} animation${A.rows.length>1?"s":""}</h2>
      <div class="btns" style="margin:0"><a class="btn" href="api.php?action=export&${new URLSearchParams(A.f)}">Exporter (Excel)</a>${A.rows.length?`<button class="terra" id="to-report">Créer le rapport</button>`:""}</div></div>
    ${A.rows.length ? `<table><tr>${can("rapports") ? "<th></th>" : ""}<th>Statut</th><th>Intervenant(e)</th><th>Date</th><th>Dép.</th><th>Point d'intervention</th><th>Marque</th><th class="num">CA</th><th>Fréquentation</th><th class="num">Photos</th></tr>
      ${A.rows.map(r => `<tr class="click" data-id="${r.id}">${can("rapports") ? `<td><input type="checkbox" class="val-ck" data-vid="${r.id}" ${r.valide_le ? "disabled" : ""} ${A.vsel && A.vsel.has(+r.id) ? "checked" : ""}></td>` : ""}
        <td>${r.valide_le ? `<span class="pill ok" title="Validé par ${esc(r.valide_par)}">Validé</span>` : `<span class="pill warn">À valider</span>`}</td><td>${esc(r.animatrice)}</td><td>${fmtD(r.date_animation)}</td><td>${esc(r.zone)}</td><td>${esc(r.magasin)}<br><span class="muted">${esc(r.ville)}</span></td><td><b>${esc(r.marque)}</b></td><td class="num">${money(r.ca, r.devise)}</td><td>${esc(r.frequentation)}</td><td class="num">${r.nb_photos}</td></tr>`).join("")}</table>`
      : `<div class="empty">Aucune animation sur cette sélection. Les animations saisies par les intervenants apparaissent ici dès leur envoi, une ligne par marque animée.</div>`}</div>
    <div id="detail"></div>`;
}
function bRapports(){
  $("#f-go").onclick = () => { A.f = {from:$("#f-from").value, to:$("#f-to").value, zone:$("#f-zone").value, marque:$("#f-marque").value, animatrice:$("#f-anim").value, statut:$("#f-statut").value}; A.vsel = new Set(); go("rapports"); };
  document.querySelectorAll("tr[data-id]").forEach(tr => tr.onclick = e => { if (e.target.closest(".val-ck")) return; showDetail(+tr.dataset.id); });
  // Validation par lot : une case par compte rendu (les lignes d'un même compte rendu sont cochées ensemble).
  A.vsel = A.vsel || new Set();
  const upd = () => { document.querySelectorAll(".val-ck").forEach(c => c.checked = A.vsel.has(+c.dataset.vid)); if ($("#val-go")) { $("#val-go").disabled = !A.vsel.size; $("#val-go").textContent = A.vsel.size ? `Valider ${A.vsel.size} compte${A.vsel.size > 1 ? "s" : ""} rendu${A.vsel.size > 1 ? "s" : ""}` : "Valider la sélection"; } };
  document.querySelectorAll(".val-ck").forEach(c => c.onchange = () => { c.checked ? A.vsel.add(+c.dataset.vid) : A.vsel.delete(+c.dataset.vid); upd(); });
  if ($("#val-all")) $("#val-all").onclick = () => { A.rows.filter(r => !r.valide_le).forEach(r => A.vsel.add(+r.id)); upd(); };
  if ($("#val-go")) $("#val-go").onclick = async () => { if (!A.vsel.size) return; try { const r = await api("valider", {ids:[...A.vsel], valide:1}); toast(r.n + " compte(s) rendu(s) validé(s) : visibles par les marques"); A.vsel = new Set(); go("rapports"); } catch(e) { toast(e.message); } };
  upd();
  if ($("#to-report")) $("#to-report").onclick = () => { Object.assign(A.rc, {from:A.f.from, to:A.f.to, zone:A.f.zone, marques: [...new Set((A.f.marque ? [+A.f.marque] : A.rows.map(r => +(A.L.marques.find(m => m.nom === r.marque) || {}).id)).filter(id => A.L.marques.some(m => +m.id === id && +m.actif)))], msgEdited:false}); go("client"); };
}
async function showDetail(id){
  const r = await api("rapport", null, "id=" + id);
  // Photos : un clic sur l'œil masque ou montre la photo aux marques (les selfies ne leur sont jamais montrés).
  const ph = (list) => list.length ? `<div class="thumbs">${list.map(p => `<div class="thumb ph-v${+p.client || p.slot === "animatrice" ? "" : " off"}"><a href="${p.full}" target="_blank" rel="noopener"><img src="${p.url}" alt=""></a>
    ${p.slot === "animatrice" ? `<span class="ph-tag">selfie · jamais montré</span>` : can("rapports") ? `<button type="button" class="ph-eye" data-phv="${p.id}" data-on="${+p.client}">${+p.client ? "Montrée aux marques" : "Masquée aux marques"}</button>` : ""}</div>`).join("")}</div>` : "";
  const block = (lab, v) => v && String(v).trim() ? `<div><div class="muted">${lab}</div><div>${esc(v).replace(/\n/g,"<br>")}</div></div>` : "";
  $("#detail").innerHTML = `<div class="card accent"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><div class="big">${esc(r.magasin)}</div>
      <div class="muted">${esc(r.ville)} · ${esc(r.zone)} · ${fmtD(r.date_animation)} · ${esc(r.animatrice)} · envoyé le ${esc(r.created_at)}${r.modifie_le ? ` · <b style="color:var(--terra)">corrigé le ${esc(r.modifie_le)}</b>` : ""}</div></div>
      ${can("rapports") ? `<div class="btns" style="margin:0">${r.valide_le ? `<span class="pill ok" style="align-self:center">Validé le ${esc(r.valide_le.slice(0,10).split("-").reverse().join("/"))} par ${esc(r.valide_par)}</span><button class="ghost" id="unval">Retirer la validation</button>` : `<button class="terra" id="val1">Valider pour les marques</button>`}<button class="primary" id="fix">Corriger</button><button class="ghost danger" id="del">Supprimer</button></div>` : ""}</div>
    ${r.marques.map(m => `<div class="brand"><div style="display:flex;align-items:center;gap:10px">${m.logo?`<img class="logo-sm" src="uploads/${m.logo}" alt="">`:""}<b>${esc(m.marque)}</b><span class="pill">${money(m.ca, r.devise)}</span>${m.objectif != null ? `<span class="muted">objectif ${money(m.objectif, r.devise)}${m.ca != null && +m.objectif > 0 ? ` · ${+m.ca >= +m.objectif ? "+" : ""}${Math.round((m.ca / m.objectif - 1) * 100)} %` : ""}</span>` : ""}</div>
      ${block("Réactions des clients", m.reactions)}${block("Ruptures", m.ruptures)}${block("Testeurs manquants", m.testeurs)}${ph(r.photos.filter(p => +p.rapport_marque_id === +m.id))}</div>`).join("")}
    <div class="row"><div><div class="muted">Fréquentation</div><div>${esc(r.frequentation)}</div></div><div><div class="muted">Concurrence présente</div><div>${esc(r.concurrence.concat(r.concurrence_autre ? [r.concurrence_autre] : []).join(", ") || "Aucune")}</div></div></div>
    ${block("Offres en cours", r.offres)}${block("Personnel et direction", r.staff)}${block("Faits marquants", r.faits)}
    ${ph(r.photos.filter(p => !p.rapport_marque_id))}</div>`;
  $("#detail").scrollIntoView({behavior:"smooth"});
  if (!$("#fix")) return;
  $("#fix").onclick = () => editRapport(r);
  if ($("#val1")) $("#val1").onclick = async () => { try { await api("valider", {ids:[r.id], valide:1}); toast("Validé : visible par les marques"); await loadRows(); go("rapports").then(() => showDetail(r.id)); } catch(e) { toast(e.message); } };
  if ($("#unval")) $("#unval").onclick = async () => { try { await api("valider", {ids:[r.id], valide:0}); toast("Validation retirée : plus visible par les marques"); await loadRows(); go("rapports").then(() => showDetail(r.id)); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-phv]").forEach(b => b.onclick = async () => { const on = b.dataset.on === "1" ? 0 : 1;
    try { await api("photo_client", {id:+b.dataset.phv, client:on}); b.dataset.on = on; b.textContent = on ? "Montrée aux marques" : "Masquée aux marques"; b.closest(".ph-v").classList.toggle("off", !on); } catch(e) { toast(e.message); } });
  let armed = false;
  $("#del").onclick = async () => { if (!armed) { armed = true; $("#del").textContent = "Confirmer la suppression"; return; }
    try { await api("delete_rapport", {id}); toast("Compte rendu supprimé"); go("rapports"); } catch(e) { toast(e.message); } };
}

function editRapport(r){
  const L = A.L, del = new Set();
  const mags = z => L.magasins.filter(m => m.zone === z);
  const inMag = r.magasin_id && L.magasins.some(m => +m.id === +r.magasin_id);
  const magSel = z => `<option value="autre">Autre point d'intervention (préciser)</option>` + mags(z).map(m => `<option value="${m.id}" ${+m.id === +r.magasin_id ? "selected" : ""}>${esc(m.nom)} · ${esc(m.ville)}</option>`).join("");
  const ta = (id, v, lab) => `<label>${lab}<textarea id="${id}" rows="3">${esc(v || "")}</textarea></label>`;
  const num = v => v == null ? "" : String(+v).replace(".", ",");
  const ph = list => list.length ? `<div class="thumbs">${list.map(p => `<div class="thumb" data-ph="${p.id}"><img src="${p.url}" alt=""><button type="button" data-phdel="${p.id}" aria-label="Retirer">✕</button></div>`).join("")}</div>` : "";
  $("#detail").innerHTML = `<div class="card accent"><h2>Corriger le compte rendu</h2>
    <div class="muted">${esc(r.animatrice)} · envoyé le ${esc(r.created_at)}. Les modifications s'appliquent aux rapports PDF et Excel envoyés ensuite.</div>
    <div class="row3"><label>Date<input type="date" id="e-date" value="${esc(r.date_animation)}"></label>
      <label>Zone<select id="e-zone">${L.zones.map(z => `<option ${z === r.zone ? "selected" : ""}>${z}</option>`).join("")}</select></label>
      <label>Point d'intervention<select id="e-mag">${magSel(r.zone)}</select></label></div>
    <div class="row" id="e-autre" ${inMag ? "hidden" : ""}><label>Nom du point d'intervention<input id="e-magn" value="${esc(r.magasin)}"></label><label>Ville<input id="e-ville" value="${esc(r.ville)}"></label></div>
    ${r.marques.map((m, i) => `<div class="brand" data-l="${m.id}">
      <div class="row3"><label>Marque<select data-f="marque_id">${L.marques.map(b => `<option value="${b.id}" ${+b.id === +m.marque_id ? "selected" : ""}>${esc(b.nom)}</option>`).join("")}</select></label>
        ${(m.parts || []).length ? `<div></div><div></div>` : `<label>CA réalisé (€)<input data-f="ca" inputmode="decimal" value="${num(m.ca)}"></label>
        <label>Objectif (€)<input data-f="objectif" inputmode="decimal" value="${num(m.objectif)}" placeholder="aucun"></label>`}</div>
      ${(m.parts || []).map(p => `<div class="row"><label>CA ${esc(p.nom)} (€)<input data-sub="${esc(p.nom)}" data-sf="ca" inputmode="decimal" value="${num(p.ca)}"></label><label>Objectif ${esc(p.nom)} (€)<input data-sub="${esc(p.nom)}" data-sf="objectif" inputmode="decimal" value="${num(p.objectif)}" placeholder="aucun"></label></div>`).join("")}
      <label>Réactions des clientes<textarea data-f="reactions" rows="3">${esc(m.reactions || "")}</textarea></label>
      <div class="row"><label>Ruptures<textarea data-f="ruptures" rows="2">${esc(m.ruptures || "")}</textarea></label><label>Testeurs manquants<textarea data-f="testeurs" rows="2">${esc(m.testeurs || "")}</textarea></label></div>
      ${ph(r.photos.filter(p => +p.rapport_marque_id === +m.id))}</div>`).join("")}
    <div><div class="muted" style="margin-bottom:6px">Fréquentation</div><div class="chips" id="e-freq">${L.frequentation.map(f => `<button type="button" class="chip" aria-pressed="${f === r.frequentation}" data-v="${f}">${f}</button>`).join("")}</div></div>
    <div><div class="muted" style="margin-bottom:6px">Intervenants concurrent(e)s présentes</div><div class="chips multi" id="e-conc">${L.concurrence.map(c => `<button type="button" class="chip" aria-pressed="${r.concurrence.includes(c)}" data-v="${esc(c)}">${esc(c)}</button>`).join("")}</div></div>
    <label>Autre marque concurrente<input id="e-conca" value="${esc(r.concurrence_autre || "")}"></label>
    ${ta("e-offres", r.offres, "Offres en cours")}${ta("e-staff", r.staff, "Personnel et direction")}${ta("e-faits", r.faits, "Faits marquants")}
    ${ph(r.photos.filter(p => !p.rapport_marque_id))}
    <div class="btns"><button class="primary" id="e-save">Enregistrer les corrections</button><button class="ghost" id="e-cancel">Annuler</button></div></div>`;
  $("#detail").scrollIntoView({behavior:"smooth"});
  let freq = r.frequentation; const conc = new Set(r.concurrence);
  $("#e-freq").onclick = e => { const b = e.target.closest(".chip"); if (!b) return; freq = b.dataset.v; document.querySelectorAll("#e-freq .chip").forEach(c => c.setAttribute("aria-pressed", c === b)); };
  $("#e-conc").onclick = e => { const b = e.target.closest(".chip"); if (!b) return; const v = b.dataset.v; conc.has(v) ? conc.delete(v) : conc.add(v); b.setAttribute("aria-pressed", conc.has(v)); };
  $("#e-zone").onchange = () => { $("#e-mag").innerHTML = magSel($("#e-zone").value); $("#e-mag").value = "autre"; $("#e-autre").hidden = false; };
  $("#e-mag").onchange = () => { $("#e-autre").hidden = $("#e-mag").value !== "autre"; };
  if (!inMag) $("#e-mag").value = "autre";
  document.querySelectorAll("[data-phdel]").forEach(b => b.onclick = () => { const id = +b.dataset.phdel; const t = b.closest(".thumb");
    if (del.has(id)) { del.delete(id); t.style.opacity = 1; b.textContent = "✕"; } else { del.add(id); t.style.opacity = .3; b.textContent = "↺"; } });
  $("#e-cancel").onclick = () => showDetail(r.id);
  $("#e-save").onclick = async () => {
    const body = { id:r.id, date_animation:$("#e-date").value, zone:$("#e-zone").value, magasin_id:$("#e-mag").value, magasin:$("#e-magn").value, ville:$("#e-ville").value,
      frequentation:freq, concurrence:[...conc], concurrence_autre:$("#e-conca").value, offres:$("#e-offres").value, staff:$("#e-staff").value, faits:$("#e-faits").value,
      marques:[...document.querySelectorAll("#detail [data-l]")].map(el => { const o = {id:+el.dataset.l}; el.querySelectorAll("[data-f]").forEach(f => o[f.dataset.f] = f.value);
        el.querySelectorAll("[data-sub]").forEach(f => { o.sub = o.sub || {}; (o.sub[f.dataset.sub] = o.sub[f.dataset.sub] || {})[f.dataset.sf] = f.value; }); return o; }),
      photos_del:[...del] };
    const gros = []; body.marques.forEach(l => { [l.ca, ...Object.values(l.sub || {}).map(x => x.ca)].forEach(x => { const n = parseFloat(String(x || "").replace(/[\s€]/g, "").replace(",", ".")); if (n > CA_ALERTE) gros.push(n); }); });
    if (gros.length && !(await askSheet("Montant élevé", gros.map(n => `<b>${euros(n)}</b> de CA`).join("<br>") + "<br><br>Est-ce bien correct ?", "Oui, c'est correct", "Corriger"))) return;
    const b = $("#e-save"); b.disabled = true;
    try { await api("update_rapport", body); toast("Corrections enregistrées"); await loadRows(); go("rapports").then(() => showDetail(r.id)); }
    catch(e) { toast(e.message); b.disabled = false; }
  };
}

/* ---------- Rapport client ---------- */
/** Zones du rapport : celle choisie, sinon celles des comptes rendus cochés. */
function clientZones(c){
  if (c.zone) return [c.zone];
  return [...new Set((c.rlist || []).filter(r => !c.rsel || c.rsel.has(r.id)).map(r => r.zone).filter(Boolean))];
}
/** Listes de destinataires des marques choisies (une par marque, selon la zone). */
function clientListes(c){
  const L = A.L.listes || [], zones = clientZones(c), out = [];
  for (const id of c.marques) {
    const cand = L.filter(l => String(l.marques || "").split(",").filter(Boolean).map(Number).includes(+id));
    const zl = l => String(l.zones || "").split(",").map(z => z.trim()).filter(Boolean);
    const l = cand.find(l => zl(l).length && zones.length && zones.every(z => zl(l).includes(z))) || cand.find(l => !zl(l).length);
    if (l && !out.includes(l)) out.push(l);
  }
  return out;
}
function clientListe(l, c){ const ls = clientListes(c); return ls.length === 1 && ls[0] === l; }
function vClient(){
  const c = A.rc;
  return `<div class="card accent"><h2>Créer un rapport d'animation</h2><div class="muted" style="margin:-4px 0 10px">Le rapport envoyé à la marque à partir des comptes rendus d'animation validés : synthèse, ventes, photos. Pour une opération d'accueil ou d'hôtessariat, utilisez l'onglet <b>Rapports événementiels</b>.</div>
    <div><div class="muted" style="margin-bottom:6px">1. Marque(s) <span class="hint">· cliquez sur une ou plusieurs marques</span></div><div class="chips multi" id="c-marques">${A.L.marques.filter(m => +m.actif).map(m => `<button type="button" class="chip" aria-pressed="${c.marques.includes(+m.id)}" data-v="${m.id}" title="${esc(m.detail || "")}">${esc(m.nom)}</button>`).join("")}</div></div>
    <label>2. Secteur géographique<select id="c-zone">${secteurOpts(c.zone)}</select></label>
    <div class="row"><label>3. Du<input type="date" id="c-from" value="${c.from}"></label><label>Au<input type="date" id="c-to" value="${c.to}"></label></div>
    <div class="photosel"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <div><b>Comptes rendus à inclure</b> <span class="hint" id="c-count"></span></div>
        <div class="btns" style="margin:0"><button type="button" class="ghost" id="c-r-all">Tout cocher</button><button type="button" class="ghost" id="c-r-none">Tout décocher</button></div></div>
      <div id="c-rlist" class="rlist"></div></div>
    <label>Recommandations au client <span class="hint">· proposées d'après les comptes rendus : modifiez, complétez ou effacez (un tiret seul pour ne rien mettre). Une ligne par recommandation.</span><textarea id="c-reco" rows="5">${esc(c.reco || "")}</textarea></label>
    <div class="btns" style="margin-top:0"><button type="button" class="ghost sm" id="c-reco-re">Proposer à nouveau</button></div>
    ${A.L.ia ? `<label style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" id="c-ia" style="width:auto" ${c.ia?"checked":""}> Rédiger la lecture et les recommandations (Claude)</label>` : ""}
    <div class="row"><span></span>
      <label style="flex-direction:row;align-items:center;gap:8px;align-self:end"><input type="checkbox" id="c-xmag" style="width:auto"> Un fichier Excel par point d'intervention</label></div>
    <div class="btns"><a class="btn" id="p-cr" target="_blank" rel="noopener">Voir le rapport</a><a class="btn" id="d-cr" target="_blank" rel="noopener">Télécharger le PDF</a><a class="btn" id="d-xl">Tableau Excel</a><a class="btn" id="d-an" target="_blank" rel="noopener">PDF de l'analyse des ventes</a></div>
    <div class="photosel"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <div><b>Photos</b> <span class="hint" id="c-ph-count"></span></div>
        <div class="btns" style="margin:0"><button type="button" class="ghost" id="c-ph-all">Tout cocher</button><button type="button" class="ghost" id="c-ph-none">Tout décocher</button><a class="btn" id="d-ph">Télécharger (ZIP)</a></div></div>
      <div class="hint">Cliquez sur une photo pour la cocher ou la décocher. Seules les photos cochées figurent dans le rapport PDF et partent avec l'e-mail.</div>
      <div id="c-ph" class="phgrid"></div>
    </div>
    <div><div class="muted" style="margin-bottom:6px">Pièces jointes de l'e-mail</div><div class="pjs">
      <label><input type="checkbox" id="c-jpdf" ${c.jpdf?"checked":""}> Rapport d'animation (PDF)</label>
      <label><input type="checkbox" id="c-jxlsx" ${c.jxlsx?"checked":""}> Tableau de reporting (Excel)</label>
      <label><input type="checkbox" id="c-analyse" ${c.analyse?"checked":""}> Analyse des ventes (PDF)</label>
      <label><input type="checkbox" id="c-joindre" ${c.joindre?"checked":""}> Photos cochées (en ZIP au-delà de 10)</label></div></div>
    <div id="c-destbox">${destPicker("c", c.to_mail, l => clientListe(l, c))}</div><div class="hint" id="c-listinfo" style="margin-top:-6px"></div>
    <label>Message de l'e-mail <span class="hint">· modifiable avant l'envoi · <a href="#" id="c-msg-reset">rétablir le message type</a></span><textarea id="c-msg" rows="9">${esc(c.msg || "")}</textarea></label>
    ${can("envoi") ? `<button class="terra" id="send">Envoyer par e-mail</button>` : `<div class="hint">Votre compte ne permet pas l'envoi aux clients.</div><button id="send" hidden></button>`}
    <div class="hint">L'e-mail aux couleurs ${AG} contient votre message et la synthèse. Les pièces jointes sont celles cochées ci-dessus, construites uniquement avec les comptes rendus et les photos cochés.</div></div>`;
}
function bClient(){
  const c = A.rc;
  c.marques = c.marques.filter(id => A.L.marques.some(m => +m.id === +id && +m.actif));
  const grpOf = () => (A.L.groupes || []).find(g => g.marques.length === c.marques.length && g.marques.every(id => c.marques.includes(id)));
  const sync = () => { const g = grpOf(); c.groupe = g ? g.nom : "";
    document.querySelectorAll("#c-groupes .chip").forEach(b => b.setAttribute("aria-pressed", !!g && +b.dataset.g === g.id));
    c.from = $("#c-from").value; c.to = $("#c-to").value; c.zone = $("#c-zone").value; c.analyse = $("#c-analyse").checked; c.ia = $("#c-ia") ? $("#c-ia").checked : false; c.to_mail = $("#c-dest").value;
    const ok = c.marques.length > 0; ["#p-cr","#d-cr","#d-xl","#d-an","#d-ph","#send"].forEach(x => $(x).classList.toggle("off", !ok)); $("#send").disabled = !ok;
    const dk = c.marques.join(",") + "|" + c.zone; if (dk !== c.destKey) { c.destKey = dk; c.destEdited = false; c.xfmtEdited = false; }
    loadReports(); loadMsg(); links(); autoDest(); };
  // Liste de destinataires : celle du client et de la zone choisis, tant que l'adresse n'a pas été changée à la main.
  const autoDest = () => { if (c.destEdited) return; const ls = clientListes(c);
    const emails = ls.length ? [...new Set(ls.flatMap(l => String(l.emails).split(/[\s,;]+/).filter(Boolean)))].join(", ") : A.L.mail_to;
    $("#c-liste").value = ls.length === 1 ? ls[0].id : ""; $("#c-dest").value = emails; c.to_mail = emails;
    const sans = c.marques.filter(id => !ls.some(l => String(l.marques || "").split(",").map(Number).includes(+id))).map(id => (A.L.marques.find(m => +m.id === +id) || {}).nom).filter(Boolean);
    $("#c-listinfo").style.color = ""; $("#c-listinfo").style.fontWeight = ""; $("#c-listinfo").textContent = (ls.length ? "Liste appliquée : " + ls.map(l => l.nom).join(" + ") + ". " : "") + (sans.length ? "Pas de liste pour " + sans.join(", ") + " : ajoutez les adresses à la main ou créez la liste (onglet Envois)." : ""); };
  /** Alerte si la liste choisie à la main ne correspond pas aux marques cochées en haut. */
  const destAlerte = () => { const id = +$("#c-liste").value; if (!id) return "";
    const l = (A.L.listes || []).find(x => +x.id === id); if (!l) return "";
    const mk = String(l.marques || "").split(",").filter(Boolean).map(Number);
    const hors = c.marques.filter(m => !mk.includes(+m)).map(m => (A.L.marques.find(x => +x.id === +m) || {}).nom).filter(Boolean);
    return hors.length ? `Attention : la liste « ${l.nom} » n'est pas celle de ${hors.join(", ")}.` : ""; };
  const showAlerte = () => { const a = destAlerte(), el = $("#c-listinfo"); if (a) { el.textContent = a; el.style.color = "#B3261E"; el.style.fontWeight = "600"; } else { el.style.color = ""; el.style.fontWeight = ""; } };
  bindDest("c", v => { c.to_mail = v; c.destEdited = true; $("#c-listinfo").textContent = ""; showAlerte(); });
  const links = () => {
    xfmtAuto();
    const base = {from:c.from, to:c.to, zone:c.zone, marques:c.marques.join(","), ia: c.ia ? 1 : "", groupe:c.groupe || "", xfmt:c.xfmt || "mois", xmag:c.xmag ? 1 : "", reco:(c.reco || "").slice(0, 3000)};
    const q = new URLSearchParams(Object.assign({}, base, rq(), c.sel ? {sel:1, photos:[...c.sel].join(",")} : {})).toString();
    $("#d-xl").href = "report.php?xlsx=1&" + q; $("#d-ph").href = "report.php?photos_zip=1&" + q;
    $("#p-cr").href = "report.php?type=cr&" + q; $("#d-cr").href = "report.php?pdf=1&type=cr&" + q; $("#d-an").href = "report.php?pdf=1&type=analyse&" + q;
    const n = c.sel ? c.sel.size : c.photos.length, kb = c.photos.filter(p => !c.sel || c.sel.has(p.id)).reduce((a, p) => a + p.poids, 0);
    $("#c-ph-count").textContent = c.photos.length ? `· ${n} cochée${n > 1 ? "s" : ""} sur ${c.photos.length} (${(kb / 1048576).toFixed(1)} Mo)` : "· aucune photo sur cette sélection";
  };
  const drawPhotos = () => {
    $("#c-ph").innerHTML = c.photos.map(p => `<button type="button" class="ph ${!c.sel || c.sel.has(p.id) ? "on" : ""}" data-ph="${p.id}" title="${esc(p.type + " · " + p.magasin + " · " + p.animatrice)}">
      <img src="${p.url}" alt="" loading="lazy"><span class="ck">✓</span><span class="cap">${fmtD(p.date).replace(/^\S+ /, "")} · ${esc(p.type)}<br>${esc(p.magasin)}</span></button>`).join("");
  };
  // Recommandations : proposées d'après la sélection tant qu'elles n'ont pas été modifiées à la main.
  let rtReco; const loadReco = (force) => { clearTimeout(rtReco); if (c.recoEdited && !force) return; rtReco = setTimeout(async () => { try { c.reco = (await api("reco_auto", null, new URLSearchParams(Object.assign({from:c.from, to:c.to, zone:c.zone, marques:c.marques.join(",")}, rq())).toString())).reco; if ($("#c-reco")) $("#c-reco").value = c.reco; if (force) c.recoEdited = false; links(); } catch(e) {} }, 400); };
  $("#c-reco").oninput = () => { c.reco = $("#c-reco").value; c.recoEdited = true; links(); };
  $("#c-reco-re").onclick = () => loadReco(true);
  let pt; const loadPhotos = () => { clearTimeout(pt); loadReco(); if (!c.marques.length) { c.photos = []; drawPhotos(); return; }
    pt = setTimeout(async () => { try {
      c.photos = (await api("report_photos", null, new URLSearchParams(Object.assign({from:c.from, to:c.to, zone:c.zone, marques:c.marques.join(",")}, rq())).toString())).photos;
      if (c.sel) { const ids = new Set(c.photos.map(p => p.id)); c.sel = new Set([...c.sel].filter(id => ids.has(id))); }
      drawPhotos(); links(); } catch(e) {} }, 300); };
  $("#c-ph").onclick = e => { const b = e.target.closest("[data-ph]"); if (!b) return; const id = +b.dataset.ph;
    if (!c.sel) c.sel = new Set(c.photos.map(p => p.id));
    c.sel.has(id) ? c.sel.delete(id) : c.sel.add(id); b.classList.toggle("on", c.sel.has(id)); links(); };
  $("#c-ph-all").onclick = () => { c.sel = new Set(c.photos.map(p => p.id)); drawPhotos(); links(); };
  $("#c-ph-none").onclick = () => { c.sel = new Set(); drawPhotos(); links(); };
  $("#c-joindre").onchange = () => { c.joindre = $("#c-joindre").checked; };
  $("#c-jpdf").onchange = () => { c.jpdf = $("#c-jpdf").checked; };
  $("#c-jxlsx").onchange = () => { c.jxlsx = $("#c-jxlsx").checked; };
  const xfmtAuto = () => { c.xfmt = "mois"; $("#c-xmag").checked = !!c.xmag; };
  $("#c-xmag").onchange = () => { c.xmag = $("#c-xmag").checked; c.xfmtEdited = true; links(); };
  const rq = () => c.rsel ? {rsel:1, rids:[...c.rsel].join(",")} : {};
  const drawReports = () => {
    $("#c-rlist").innerHTML = c.rlist.length ? c.rlist.map(r => `<label class="ritem ${!c.rsel || c.rsel.has(r.id) ? "on" : ""}"><input type="checkbox" data-r="${r.id}" ${!c.rsel || c.rsel.has(r.id) ? "checked" : ""}>
      <span>${fmtD(r.date)}</span><span><b>${esc(r.animatrice)}</b></span><span>${esc(r.magasin)}<span class="muted"> · ${esc(r.zone)}</span></span><span>${esc(r.marques.join(", "))}</span><span class="num">${money(r.ca, "EUR")}</span></label>`).join("")
      : `<div class="empty">${c.marques.length ? "Aucune animation pour cette sélection." : "Choisissez au moins une marque."}</div>`;
    const n = c.rsel ? c.rsel.size : c.rlist.length;
    $("#c-count").textContent = c.rlist.length ? `· ${n} coché${n > 1 ? "s" : ""} sur ${c.rlist.length}` : "";
  };
  let rt; const loadReports = () => { clearTimeout(rt); if (!c.marques.length) { c.rlist = []; drawReports(); loadPhotos(); return; }
    rt = setTimeout(async () => { try {
      const rows = (await api("rapports", null, new URLSearchParams({from:c.from, to:c.to, zone:c.zone}).toString())).rapports
        .filter(x => c.marques.includes(+A.L.marques.find(m => m.nom === x.marque)?.id));
      const by = new Map();
      rows.forEach(x => { const r = by.get(x.id) || {id:+x.id, date:x.date_animation, animatrice:x.animatrice, magasin:x.magasin, zone:x.zone, marques:[], ca:0}; r.marques.push(x.marque); r.ca += +x.ca || 0; by.set(x.id, r); });
      c.rlist = [...by.values()].sort((a, b) => a.date < b.date ? -1 : a.date > b.date ? 1 : a.magasin.localeCompare(b.magasin));
      if (c.rsel) { const ids = new Set(c.rlist.map(r => r.id)); c.rsel = new Set([...c.rsel].filter(id => ids.has(id))); }
      drawReports(); loadPhotos(); links(); autoDest(); } catch(e) {} }, 250); };
  $("#c-rlist").onchange = e => { const i = e.target.closest("[data-r]"); if (!i) return; const id = +i.dataset.r;
    if (!c.rsel) c.rsel = new Set(c.rlist.map(r => r.id));
    i.checked ? c.rsel.add(id) : c.rsel.delete(id); i.closest(".ritem").classList.toggle("on", i.checked); drawReports(); loadPhotos(); links(); autoDest(); };
  $("#c-r-all").onclick = () => { c.rsel = null; drawReports(); loadPhotos(); links(); };
  $("#c-r-none").onclick = () => { c.rsel = new Set(); drawReports(); loadPhotos(); links(); };
  ["#c-from","#c-to","#c-zone","#c-analyse","#c-ia"].forEach(s => { const el = $(s); if (el) el.addEventListener("change", sync); });
  if ($("#c-groupes")) $("#c-groupes").onclick = e => { const b = e.target.closest(".chip"); if (!b) return; const g = A.L.groupes.find(x => x.id === +b.dataset.g);
    const actifs = new Set(A.L.marques.filter(m => +m.actif).map(m => +m.id));
    c.marques = g.marques.filter(id => actifs.has(id)); c.rsel = null; c.sel = null;
    document.querySelectorAll("#c-marques .chip").forEach(x => x.setAttribute("aria-pressed", c.marques.includes(+x.dataset.v))); sync(); };
  $("#c-marques").onclick = e => { const b = e.target.closest(".chip"); if (!b) return; const v = +b.dataset.v;
    c.marques = c.marques.includes(v) ? c.marques.filter(x => x !== v) : c.marques.concat(v); b.setAttribute("aria-pressed", c.marques.includes(v)); sync(); };
  let mt, lastKey = ""; const loadMsg = (force) => { clearTimeout(mt); if (!c.marques.length) return;
    const key = [c.marques.join(","), c.from, c.to, c.zone].join("|"); if (key === lastKey && !force) return; lastKey = key;
    mt = setTimeout(async () => { try { c.msg = (await api("mail_message", null, new URLSearchParams({from:c.from, to:c.to, zone:c.zone, marques:c.marques.join(","), groupe:c.groupe || ""}).toString())).message; c.msgEdited = false; $("#c-msg").value = c.msg; } catch(e) {} }, 300); };
  $("#c-msg").oninput = () => { c.msg = $("#c-msg").value; c.msgEdited = true; };
  $("#c-msg-reset").onclick = e => { e.preventDefault(); loadMsg(true); };
  sync();
  $("#send").onclick = async () => { sync(); const al = destAlerte();
    if (al && !(await askSheet("Vérifiez les destinataires", esc(al) + "<br><br>Envoyer quand même ?", "Envoyer", "Corriger"))) return;
    const b = $("#send"); b.disabled = true; b.textContent = "Préparation des PDF et envoi…";
    try { const r = await api("send", {reco:c.reco || "", from:c.from, to:c.to, zone:c.zone, marques:c.marques, groupe:c.groupe || "", analyse:c.analyse, ia:c.ia, dest:c.to_mail, message:$("#c-msg").value, joindre_photos:c.joindre, joindre_pdf:c.jpdf, joindre_xlsx:c.jxlsx, xfmt:c.xfmt || "mois", xmag:c.xmag ? 1 : 0, ...(c.rsel ? {rsel:1, rids:[...c.rsel]} : {}), sel:c.sel ? 1 : 0, photos:c.sel ? [...c.sel] : []}); toast(r.message); try { const L = await api("admin_lists"); A.L.envois = L.envois; } catch(_) {} }
    catch(e) { toast(e.message); } b.disabled = false; b.textContent = "Envoyer par e-mail"; };
}

/* ---------- Intervenants ---------- */
const ficheJ = j => { try { return JSON.parse(j || "{}") || {}; } catch(e) { return {}; } };
function vDoublons(){
  const D = A.L.doublons || []; if (!D.length) return "";
  return `<div class="card" style="border-color:#E9B4AA"><h2>Doublons possibles (${D.length})</h2><div class="muted">Même nom, même e-mail ou même portable. Choisissez la fiche à garder (en général celle inscrite dans l'appli) : rapports, planning, contrats, relevés et pièces de l'autre lui sont rattachés, puis l'autre est désactivée.</div>
    ${D.map((g, i) => `<div class="dbl"><table><tr><th></th><th>Matricule</th><th>Nom</th><th>Zone</th><th>Portable</th><th>E-mail</th><th>Inscrite le</th><th class="num">Rapports</th><th class="num">Dates</th></tr>
      ${g.map((a, k) => `<tr><td><input type="radio" name="dbl${i}" value="${a.id}" ${k === g.findIndex(x => x.created_at) || (k === 0 && !g.some(x => x.created_at)) ? "checked" : ""}></td><td class="hint">${esc(a.matricule || "")}</td><td><b>${esc(nm(a))}</b>${+a.actif ? "" : ` <span class="pill p-bad">bloquée</span>`}</td><td>${esc(a.zone)}</td><td>${esc(a.telephone || "")}</td><td>${esc(a.email || "")}</td><td>${a.created_at ? esc(String(a.created_at).slice(0, 10).split("-").reverse().join("/")) : `<span class="hint">jamais</span>`}</td><td class="num">${a.rapports}</td><td class="num">${a.missions}</td></tr>`).join("")}</table>
      <div class="btns"><button class="ghost" data-dbl="${i}">Garder la fiche cochée et fusionner</button></div></div>`).join("")}</div>`;
}
function vAnim(){
  const n = A.L.animatrices.length, act = A.L.animatrices.filter(a => +a.actif).length;
  const AT = A.L.animatrices.filter(a => a.statut === "attente").sort((x, y) => String(y.created_at || "").localeCompare(String(x.created_at || "")));
  const anti = AT.length ? `<div class="card" style="border-color:#E2CDBD"><h2>Antichambre · ${AT.length} nouvelle${AT.length > 1 ? "s" : ""} inscription${AT.length > 1 ? "s" : ""} à valider</h2>
    <div class="muted">Personnes inscrites seules dans l'appli. Elles peuvent remplir leur fiche, mais ne reçoivent pas d'offres de mission tant que vous ne les avez pas validées. Validées, elles rejoignent le tableau du personnel.</div>
    <div class="tablewrap"><table><tr><th>Personne</th><th>Contact</th><th>Lieu</th><th>Inscrite le</th><th>Fiche</th><th></th></tr>${AT.map(a => { const f = ficheJ(a.fiche), rempli = ["adresse", "ville", "naissance_date", "secu", "iban"].filter(k => (f[k] || "") !== "").length;
      return `<tr><td><button type="button" class="linkbtn" data-fiche="${a.id}">${esc(nm(a))}</button><div class="hint">${esc(a.matricule || "")}</div></td><td>${esc(a.telephone || "")}${a.email ? `<div class="hint">${esc(a.email)}</div>` : ""}</td><td>${esc(a.zone)}<div class="hint">${esc(f.ville || "")}</div></td>
        <td>${a.created_at ? esc(fmtD(a.created_at.slice(0, 10))) : ""}</td><td>${rempli}/5 <span class="hint">infos clés</span>${a.photo ? " · photo" : ""}</td>
        <td class="kact"><button class="primary sm" data-kok="${a.id}">Valider</button><button class="ghost sm danger" data-kno="${a.id}">Refuser</button></td></tr>`; }).join("")}</table></div></div>` : "";
  return anti + vDoublons() + `<div class="card accent"><h2>Code d'accès des intervenants</h2>
    <div class="muted">Un seul code pour toutes. À la première connexion, chaque intervenant(e) saisit ce code, son prénom, son nom, son portable et sa zone : elle s'inscrit seule et apparaît dans la liste ci-dessous.</div>
    <div class="row"><label>Code ${AG} actuel<input id="k-code" value="${esc(A.L.code_agence)}" style="font-weight:600;letter-spacing:.08em;text-transform:uppercase"></label><label>&nbsp;<button class="primary" id="k-save">Changer le code</button></label></div>
    <div class="hint">Lien à leur envoyer : ${esc(location.href.replace(/admin\.php.*$/, ""))} · Changer le code n'a aucun effet sur les intervenants déjà connectées ; il bloque seulement les nouvelles inscriptions avec l'ancien.</div></div>
    <div class="card"><h2>Importer une liste de personnes</h2>
      <div class="muted">Pour donner des droits sur les formations avant même leur inscription. Le plus simple : choisissez directement votre <b>fichier Excel</b> (export Plany par exemple). Les colonnes <b>Nom, Prénom, Email, Téléphone, Code postal, Pays</b> sont reconnues grâce à la première ligne, dans n'importe quel ordre ; la zone est déduite du pays ou du code postal. Les autres colonnes (ville, nationalité…) ne sont pas enregistrées. Vous pouvez aussi coller les lignes copiées depuis Excel, en-tête compris. Les personnes déjà présentes ne sont pas recréées. À son inscription, la personne est reconnue (portable, e-mail, ou prénom et nom) et retrouve ses droits.</div>
      <textarea id="imp-txt" rows="5" placeholder="Julie	MARTIN	06 12 34 56 78	julie@mail.fr	France"></textarea>
      <div class="row3"><label>Fichier Excel ou CSV<input id="imp-file" type="file" accept=".xlsx,.csv,.txt"></label><label>Zone par défaut<select id="imp-zone">${zoneOpts("France")}</select></label><label>&nbsp;<button class="primary" id="imp-go">Importer</button></label></div>
      <label class="ck"><input type="checkbox" id="imp-ecr"><span>Remplacer les informations déjà présentes sur les fiches par celles du fichier</span></label><div id="imp-res"></div>
      <div class="hint">Colonnes reconnues sur la ligne d'en-tête, dans n'importe quel ordre : Nom, Prénom, Email, Téléphone, Adresse (plusieurs colonnes possibles), Code postal, Ville, Pays, Nationalité, Civilité ou Sexe, N° de sécurité sociale, Date et lieu de naissance, SIRET. Un 1er clic montre ce qui sera fait et les colonnes lues, sans rien enregistrer. Les personnes déjà présentes ne sont jamais dupliquées : sans la case cochée, seuls les champs vides de leur fiche sont complétés.</div></div>
    ${(A.L.moi || {}).gestion ? `<div class="card"><h2>Garder seulement les personnes d'un fichier</h2>
      <div class="muted">En cas d'import par erreur : choisissez le <b>bon fichier</b>. Les personnes qui n'y figurent pas (comparées par portable, e-mail ou nom et prénom) sont retirées, à condition de ne s'être jamais inscrites dans l'appli et de n'avoir aucune activité (compte rendu, planning, contrat, relevé). Les autres sont gardées et listées. Un 1er clic montre la liste, sans rien retirer.</div>
      <div class="row"><label>Le bon fichier (Excel ou CSV)<input id="nt-file" type="file" accept=".xlsx,.csv,.txt"></label><label>&nbsp;<button class="primary" id="nt-go">Voir qui serait retiré</button></label></div><div id="nt-res"></div></div>` : ""}
    <div class="card tablebox"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>${n} personne${n>1?"s":""} · ${A.L.animatrices.filter(a => a.created_at).length} inscrite${A.L.animatrices.filter(a => a.created_at).length>1?"s":""} · ${act} active${act>1?"s":""}</h2>
      <input id="k-q" placeholder="Rechercher un nom, un numéro, une zone" style="max-width:320px" value="${esc(A.q||"")}"></div>
    <div class="kfilt"><select id="kf-st"><option value="">Tous statuts</option>${Object.entries(A.L.statuts_anim || {}).map(([k, l]) => `<option value="${k}">${l}</option>`).join("")}</select><select id="kf-zone"><option value="">Tous départements</option>${Object.entries(A.L.departements || {}).map(([k, v]) => `<option value="${k}">${k} · ${esc(v)}</option>`).join("")}</select>
      <select id="kf-met"><option value="">Tous métiers</option>${(A.L.fiche_listes?.metiers || []).map(v => `<option>${v}</option>`).join("")}</select>
      <select id="kf-comp"><option value="">Toutes compétences</option><optgroup label="Animation">${(A.L.fiche_listes?.competences || []).map(v => `<option>${v}</option>`).join("")}</optgroup><optgroup label="Événementiel">${(A.L.fiche_listes?.accueil || []).map(v => `<option>${v}</option>`).join("")}</optgroup></select>
      <select id="kf-jour"><option value="">Tous les jours</option>${(A.L.fiche_listes?.jours || []).map(v => `<option>${v}</option>`).join("")}</select>
      <select id="kf-lang"><option value="">Toutes langues</option>${(A.L.fiche_listes?.langues || []).map(v => `<option>${v}</option>`).join("")}</select>
      <label class="ck"><input type="checkbox" id="kf-ae"><span>Auto-entrepreneurs</span></label><label class="ck"><input type="checkbox" id="kf-veh"><span>Véhicule</span></label><label class="ck"><input type="checkbox" id="kf-noct"><span>Nocturnes</span></label>
      <span class="hint" id="kf-n"></span></div>
    ${n ? `<table id="k-tab" class="ktab"><tr><th>Intervenant(e)</th><th>Contact</th><th>Lieu</th><th>Inscription</th><th>Activité</th><th>Statut</th><th></th></tr>
    ${[...A.L.animatrices].filter(a => a.statut !== "attente").sort(byNom).map(a => { const f = ficheJ(a.fiche);
      return `<tr data-q="${esc(((a.matricule||"")+" "+a.prenom+" "+a.nom+" "+a.telephone+" "+(a.email||"")+" "+a.zone+" "+(f.ville||"")).toLowerCase())}" data-id="${a.id}">
      <td><button type="button" class="linkbtn" data-fiche="${a.id}">${a.photo ? `<img class="mini-av" src="photo.php?f=${a.photo}&t=1" alt="">` : ""}${esc(nm(a))}</button><div class="hint">${esc(a.matricule || "")}${a.modele && A.L.ba_modeles[a.modele] ? ` · <span class="pill p-warn">BA ${esc(A.L.ba_modeles[a.modele].nom)}</span>` : ""}</div></td>
      <td>${esc(a.telephone || "")}${a.email ? `<div class="hint kmail"><a href="mailto:${esc(a.email)}" title="${esc(a.email)}">${esc(a.email)}</a></div>` : ""}</td>
      <td>${esc(a.zone)}<div class="hint">${esc([f.ville, f.nationalite].filter(Boolean).join(" · "))}</div></td>
      <td>${a.created_at ? esc(fmtD(a.created_at.slice(0,10))) : `<span class="hint" style="color:var(--terra)">pas inscrite</span>`}<div class="hint">photos : ${a.rgpd_image_at ? "accord" : "non"}</div></td>
      <td>${+a.nb ? a.nb + " CR" : `<span class="hint">aucun CR</span>`}${a.derniere ? `<div class="hint">dernier ${esc(fmtD(a.derniere))}</div>` : ""}</td>
      <td><select class="kst kst-${esc(a.statut || "actif")}" data-kst="${a.id}">${Object.entries(A.L.statuts_anim || {actif:"Actif"}).map(([k, l]) => `<option value="${k}" ${(a.statut || "actif") === k ? "selected" : ""}>${l}</option>`).join("")}</select></td>
      <td class="kact"><button class="ghost sm" data-kview="${a.id}" title="Voir son appli">Appli</button><button class="ghost sm danger" data-erase="${a.id}" title="Effacer ses données personnelles">Effacer</button></td></tr>`; }).join("")}</table>`
    : `<div class="empty">Aucun(e) intervenant(e) inscrite pour l'instant. Envoyez-leur le lien et le code ${AG} : elles apparaîtront ici dès leur première connexion.</div>`}</div>`;
}
function bAnim(){
  document.querySelectorAll("[data-dbl]").forEach(b => b.onclick = async () => { const i = +b.dataset.dbl, g = A.L.doublons[i], garder = +(document.querySelector(`[name="dbl${i}"]:checked`) || {}).value;
    if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer la fusion (irréversible)"; return; }
    try { const r = await api("fusionner", {garder, absorber:g.map(a => +a.id).filter(x => x !== garder)}); await reloadLists(); toast(r.n + " fiche" + (r.n > 1 ? "s fusionnées" : " fusionnée")); go("animatrices"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-kview]").forEach(b => b.onclick = async () => { const w = window.open("about:blank", "_blank");
    try { await api("apercu_start", {id:+b.dataset.kview}); w.location.href = "index.php"; } catch(e) { w && w.close(); toast(e.message); } });
  // Import en deux temps : aperçu (rien d'enregistré), puis confirmation.
  $("#imp-go").onclick = async () => { const b = $("#imp-go"), ok = b.dataset.ok === "1";
    const fd = new FormData(); fd.append("texte", $("#imp-txt").value); fd.append("zone", $("#imp-zone").value); if ($("#imp-file").files[0]) fd.append("fichier", $("#imp-file").files[0]); if (!ok) fd.append("simuler", "1"); if ($("#imp-ecr").checked) fd.append("ecraser", "1");
    b.disabled = true; b.textContent = ok ? "Import en cours…" : "Lecture du fichier…";
    try { const r = await api("import_animatrices", fd);
      if (!ok) { $("#imp-res").innerHTML = `<div class="plan-test">${esc(r.message)}</div>`; b.dataset.ok = "1"; b.disabled = false; b.textContent = "Confirmer l'import"; return; }
      await reloadLists(); toast(r.message); go("animatrices"); }
    catch(e) { toast(e.message); b.disabled = false; b.dataset.ok = ""; b.textContent = "Importer"; } };
  if ($("#nt-go")) { const nb = $("#nt-go");
    $("#nt-file").onchange = () => { nb.dataset.ok = ""; nb.textContent = "Voir qui serait retiré"; $("#nt-res").innerHTML = ""; };
    nb.onclick = async () => { const f = $("#nt-file").files[0]; if (!f) return toast("Choisissez le bon fichier");
      const ok = nb.dataset.ok === "1", fd = new FormData(); fd.append("fichier", f); if (ok) { fd.append("confirmer", "1"); fd.append("ids", JSON.stringify([...document.querySelectorAll("[data-ntid]:checked")].map(c => +c.dataset.ntid))); } nb.disabled = true;
      try { const r = await api("nettoyer_liste", fd), R = r.retirer, G2 = r.garde;
        $("#nt-res").innerHTML = `<div class="plan-test"><b>${r.simule ? "Aperçu : " : ""}${R.length} personne${R.length > 1 ? "s" : ""} ${r.simule ? "à retirer" : "retirée" + (R.length > 1 ? "s" : "")}</b> (le fichier en contient ${r.fichier}).
          ${R.length ? `<details ${r.simule ? "open" : ""}><summary>Voir la liste${r.simule ? " (décochez celles à garder)" : ""}</summary><div class="hint" style="max-height:360px;overflow:auto">${R.map(x => r.simule ? `<label class="ck"><input type="checkbox" data-ntid="${x.id}" checked><span>${esc(x.nom)}</span></label>` : esc(x.nom)).join(r.simule ? "" : "<br>")}</div></details>` : ""}
          ${G2.length ? `<details><summary>${G2.length} absente${G2.length > 1 ? "s" : ""} du fichier mais gardée${G2.length > 1 ? "s" : ""}</summary><div class="hint">${G2.map(esc).join("<br>")}</div></details>` : ""}</div>`;
        if (r.simule && R.length) { nb.dataset.ok = "1"; const maj = () => { const n = document.querySelectorAll("[data-ntid]:checked").length; nb.textContent = "Confirmer : retirer " + n + " personne" + (n > 1 ? "s" : ""); }; maj();
          document.querySelectorAll("[data-ntid]").forEach(c => c.onchange = maj); }
        else { nb.dataset.ok = ""; nb.textContent = "Voir qui serait retiré"; if (!r.simule) { await reloadLists(); toast("Liste nettoyée"); go("animatrices"); } }
      } catch(e) { toast(e.message); } nb.disabled = false; };
  }
  ["#imp-file", "#imp-txt", "#imp-zone", "#imp-ecr"].forEach(k => $(k).addEventListener("change", () => { $("#imp-go").dataset.ok = ""; $("#imp-go").textContent = "Importer"; $("#imp-res").innerHTML = ""; }));
  $("#k-save").onclick = async () => { try { const r = await api("save_code", {code:$("#k-code").value}); await reloadLists(); toast("Nouveau code : " + r.code); go("animatrices"); } catch(e) { toast(e.message); } };
  const fic = {}; A.L.animatrices.forEach(a => { try { fic[a.id] = JSON.parse(a.fiche || "{}") || {}; } catch(e) { fic[a.id] = {}; } });
  const filt = () => { const q = (A.q = $("#k-q").value).toLowerCase().trim();
    const st = $("#kf-st").value, z = $("#kf-zone").value, c = $("#kf-comp").value, me = $("#kf-met").value, j = $("#kf-jour").value, l = $("#kf-lang").value, v = $("#kf-veh").checked, n = $("#kf-noct").checked, ae = $("#kf-ae").checked; let k = 0;
    const depsDe = f => { const o = new Set(f.mob_deps || []); (f.mob_communes || []).forEach(c => o.add(c.dep)); const cp = String(f.code_postal || "").replace(/\D/g, ""); if (cp.length === 5) o.add(cp.startsWith("20") ? (+cp < 20200 ? "2A" : "2B") : cp.slice(0, 2)); return o; };
    document.querySelectorAll("#k-tab tr[data-q]").forEach(tr => { const a = A.L.animatrices.find(x => +x.id === +tr.dataset.id), f = fic[tr.dataset.id] || {};
      const ok = (!q || tr.dataset.q.includes(q)) && (!st || (a.statut || "actif") === st) && (!z || depsDe(f).has(z)) && (!ae || /auto-entrepreneu/i.test(f.statut || "")) && (!c || (f.competences || []).includes(c) || (f.accueil || []).includes(c)) && (!me || (f.metiers || []).includes(me)) && (!j || (f.jours || []).includes(j)) && (!l || (f.langues || []).includes(l)) && (!v || f.vehicule) && (!n || f.nocturnes);
      tr.hidden = !ok; if (ok) k++; });
    $("#kf-n").textContent = k + " personne" + (k > 1 ? "s" : ""); };
  ["#kf-st","#kf-zone","#kf-comp","#kf-met","#kf-jour","#kf-lang","#kf-veh","#kf-noct","#kf-ae"].forEach(s => $(s).onchange = filt);
  document.querySelectorAll("[data-fiche]").forEach(b => b.onclick = () => showFiche(+b.dataset.fiche));
  $("#k-q").oninput = filt; filt();
  let armedE = 0;
  document.querySelectorAll("[data-erase]").forEach(b => b.onclick = async () => { const id = +b.dataset.erase;
    if (armedE !== id) { armedE = id; b.textContent = "Confirmer l'effacement"; return; }
    try { await api("erase_animatrice", {id}); await reloadLists(); toast("Données effacées : identité et photos supprimées, chiffres conservés anonymement"); go("animatrices"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-kok],[data-kno]").forEach(b => b.onclick = async () => { const id = +(b.dataset.kok || b.dataset.kno), st = b.dataset.kok ? "actif" : "refuse";
    if (st === "refuse" && b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer le refus"; return; }
    try { await api("statut_animatrice", {id, statut:st}); const a = A.L.animatrices.find(x => +x.id === id); a.statut = st; a.actif = st === "refuse" ? 0 : 1; toast(nm(a) + (st === "actif" ? " validé(e) : dans le tableau du personnel" : " refusé(e)")); go("animatrices"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-kst]").forEach(sl => sl.onchange = async () => { try { await api("statut_animatrice", {id:+sl.dataset.kst, statut:sl.value}); const a = A.L.animatrices.find(x => +x.id === +sl.dataset.kst); a.statut = sl.value; a.actif = sl.value === "refuse" ? 0 : 1; sl.className = "kst kst-" + sl.value; toast("Statut : " + sl.options[sl.selectedIndex].text); } catch(e) { toast(e.message); } });
}

/** Fiche complète d'un(e) intervenant(e) (vue administratrice). */
async function showFiche(id){
  const a = A.L.animatrices.find(x => +x.id === id); let F;
  try { F = await api("fiche_admin", null, "id=" + id); } catch(e) { return toast(e.message); }
  const d = (F.data && !Array.isArray(F.data)) ? F.data : {}, s = F.stats || {};
  const row = (lab, v) => v !== undefined && v !== null && String(v).trim() !== "" ? `<div class="fr"><span>${lab}</span><div>${v}</div></div>` : `<div class="fr"><span>${lab}</span><div class="muted">non renseigné</div></div>`;
  const list = x => (x || []).length ? x.map(v => `<span class="pill">${esc(v)}</span>`).join(" ") : "";
  document.getElementById("fiche-modal")?.remove();
  const m = document.createElement("div"); m.id = "fiche-modal"; m.className = "modal";
  m.innerHTML = `<div class="modal-box"><button class="ghost modal-x" data-x>✕</button>
    <div class="fiche-top">${F.photo ? `<img class="big-av" src="${F.photo}" alt="">` : `<div class="big-av empty-av">${esc((a.prenom || "?")[0])}</div>`}
      <div><div class="big">${esc(nm(a))}</div><div class="muted">${esc(a.zone)} · ${esc(a.telephone || "pas de portable")} · ${a.email ? `<a href="mailto:${esc(a.email)}">${esc(a.email)}</a>` : "pas d'e-mail"}</div>
      <div class="muted">${a.created_at ? "Inscrite le " + fmtD(a.created_at.slice(0, 10)) : "Pas encore inscrite"}${F.updated_at ? " · fiche mise à jour le " + fmtD(F.updated_at.slice(0, 10)) : " · fiche non remplie"}</div></div></div>
    ${can("tout") ? `<details class="ldet"><summary><b>Modifier le nom, le portable, l'e-mail ou la zone</b> <span class="hint">· par exemple pour un prénom d'usage</span></summary>
      <div class="row"><label>Prénom affiché<input id="fi-pr" value="${esc(a.prenom)}"></label><label>Nom<input id="fi-no" value="${esc(a.nom)}"></label></div>
      <div class="row3"><label>Portable<input id="fi-te" value="${esc(a.telephone || "")}"></label><label>E-mail<input id="fi-em" value="${esc(a.email || "")}"></label><label>Zone<select id="fi-zo">${zoneOpts(a.zone)}</select></label></div>
      <div class="hint">Le nom affiché est celui qu'elle voit dans l'appli et celui qui figure sur ses comptes rendus, reportings et e-mails aux clients, y compris pour les journées déjà saisies.</div>
      <div class="btns"><button class="primary" id="fi-save">Enregistrer</button></div></details>` : ""}
    <div class="kpis4"><div><b>${s.nb || 0}</b><span>animations</span></div><div><b>${money(s.ca || 0, "EUR")}</b><span>CA total</span></div><div><b>${s.nb ? money((s.ca || 0) / s.nb, "EUR") : "–"}</b><span>CA moyen</span></div><div><b>${s.derniere ? fmtD(s.derniere) : "–"}</b><span>dernière animation</span></div></div>
    ${(F.par_marque || []).length ? `<div class="fr"><span>Animations par marque</span><div>${F.par_marque.map(x => `<span class="pill">${esc(x.nom)} · ${x.n}</span>`).join(" ")}</div></div>` : ""}
    <div class="fr"><span>Formations accessibles</span><div>${F.formations}</div></div>
    <h3 class="fh">Profil</h3>
    ${row("Adresse", esc([d.adresse, [d.code_postal, d.ville].filter(Boolean).join(" ")].filter(Boolean).join(", ")))}
    ${row("Statut", esc(d.statut || "") + (d.siret ? " · SIRET " + esc(d.siret) : ""))}
    ${row("Mobilité", [d.permis ? "Permis B" : "", d.vehicule ? "Véhicule" : ""].filter(Boolean).join(" · ") + ((d.mob_deps || []).length ? `<br>Départements : ${list((d.mob_deps || []).map(k => k + " " + ((A.L.departements || {})[k] || "")))}` : "") + ((d.mob_communes || []).length ? `<br>Communes : ${list(d.mob_communes.map(c => c.nom + " (" + c.dep + ")"))}` : "") + (d.mobilite ? `<br>${esc(d.mobilite)}` : ""))}
    ${row("Disponibilités", list(d.jours) + (d.nocturnes ? ` <span class="pill p-ok">Nocturnes</span>` : ""))}
    ${row("Langues", list(d.langues) + (d.langues_autres ? " " + esc(d.langues_autres) : ""))}
    ${row("Métiers", list(d.metiers))}
    ${row("Compétences", list(d.competences))}
    ${row("Événementiel", list(d.accueil))}
    ${row("Certificats", list(d.certifs) + (d.etudes ? ` <span class="hint">· études : ${esc(d.etudes)}</span>` : ""))}
    ${row("Expérience", d.experience ? d.experience + " an" + (d.experience > 1 ? "s" : "") : "")}
    ${row("Expériences marquantes", esc(d.marques || "").replace(/\n/g, "<br>"))}
    ${row("Tailles", [d.taille_haut ? "Haut " + d.taille_haut : "", d.taille_bas ? "Bas " + d.taille_bas : "", d.pointure ? "Pointure " + d.pointure : "", d.stature ? d.stature + " cm" : ""].filter(Boolean).join(" · "))}
    ${row("Book", (F.book || []).length ? `<div class="ph-grid">${F.book.map(b => `<a href="${b.full}" target="_blank" rel="noopener" class="ph-it"><img src="${b.url}" alt=""></a>`).join("")}</div>` : "")}
    ${row("Présentation", esc(d.presentation || "").replace(/\n/g, "<br>"))}
    ${row("CV", F.cv ? `<a href="fiche_fichier.php?id=${id}" target="_blank" rel="noopener">${esc(F.cv_nom || "CV")}</a>` : "")}
    <h3 class="fh">Contrat de travail <span class="hint">· matricule ${esc(F.matricule || "")}</span></h3>
    ${row("Identité", esc([d.civilite, d.nationalite].filter(Boolean).join(" · ")) + (d.naissance_date ? "<br>Né(e) le " + esc(fmtD(d.naissance_date)) + (d.naissance_lieu ? " à " + esc(d.naissance_lieu) : "") : ""))}
    ${row("Nom de naissance", esc(d.nom_naissance || ""))}
    ${row("IBAN · BIC", d.iban ? `<span style="font-family:ui-monospace,Menlo,monospace">${esc(String(d.iban).replace(/(.{4})(?=.)/g, "$1 "))}</span>${d.bic ? " · " + esc(d.bic) : ""}` : "")}
    ${row("N° de sécurité sociale", d.secu ? `<span style="font-family:ui-monospace,Menlo,monospace">${esc(d.secu)}</span>` : "")}
    ${ficheDocs(F)}
    <h3 class="fh">Notes internes <span class="hint">· visibles uniquement par l'administration</span></h3>
    <textarea id="fn-txt" rows="4" placeholder="Appréciation, points forts, à revoir…">${esc(F.notes_admin || "")}</textarea>
    <div class="btns"><button class="primary" id="fn-save">Enregistrer les notes</button></div>
    ${can("tout") ? `<h3 class="fh">Accès à l'appli</h3><div class="muted">Téléphone perdu ou changé, départ, doute : déconnecte tous ses appareils. Si son accès reste actif, elle se reconnecte avec un code reçu par e-mail.</div>
    <div class="btns"><button class="ghost" id="fn-deco">Déconnecter ses téléphones</button></div>
    <h3 class="fh">Ses données</h3><div class="muted">Demande d'accès ou de portabilité (RGPD) : un fichier ZIP avec toutes ses données, documents déchiffrés compris, à lui remettre par un moyen sûr.</div>
    <div class="btns"><a class="btn ghost" href="api.php?action=exp_personne&id=${id}">Exporter ses données</a></div>` : ""}</div>`;
  document.body.appendChild(m);
  m.onclick = e => { if (e.target === m || e.target.closest("[data-x]")) m.remove(); };
  if ($("#fi-save")) $("#fi-save").onclick = async () => { try { await api("save_identite", {id, prenom:$("#fi-pr").value, nom:$("#fi-no").value, telephone:$("#fi-te").value, email:$("#fi-em").value, zone:$("#fi-zo").value});
    toast("Enregistré"); m.remove(); await reloadLists(); go(A.tab); } catch(e) { toast(e.message); } };
  $("#fn-save").onclick = async () => { try { await api("fiche_notes", {id, notes:$("#fn-txt").value}); toast("Notes enregistrées"); } catch(e) { toast(e.message); } };
  if ($("#fn-deco")) $("#fn-deco").onclick = async () => { if (!confirm("Déconnecter tous les appareils de cette personne ?")) return; try { await api("anim_deconnecter", {id}); toast("Appareils déconnectés"); } catch(e) { toast(e.message); } };
  bFicheDocs(F, id);
}

/* ---------- Marques ---------- */
function vMarques(){
  return `<div class="card"><h2>Ajouter une marque</h2><div class="row"><label>Nom<input id="b-nom" placeholder="Ex. Maison Aurore"></label><label>Logo (PNG de préférence)<input id="b-logo" type="file" accept="image/png,image/jpeg,image/webp"></label></div>
    <div class="row"><label>Marques entre parenthèses <span class="hint">facultatif</span><input id="b-detail" placeholder="Ex. Aurore Parfums, Aurore Soin"></label><label>CA saisi séparément pour <span class="hint">facultatif, séparées par des virgules</span><input id="b-sous" placeholder="Ex. Ligne parfum, ligne soin"></label></div>
    <button class="primary" id="b-add">Ajouter</button></div>
    ${vMcRefs()}
    <div class="card"><h2>Marques concurrentes</h2><div class="muted">Proposées en cases à cocher dans les comptes rendus (« concurrence présente »). Une par ligne. Laissez vide pour une simple saisie libre.</div>
      <label><textarea id="cc-liste" rows="4" placeholder="Une marque par ligne">${esc((A.L.concurrence || []).join("\n"))}</textarea></label><div class="btns"><button class="ghost" id="cc-save">Enregistrer</button></div></div>
    <div class="card tablebox"><h2>Marques proposées aux intervenants</h2><div class="muted">L'ordre est celui de l'appli. Les marques masquées n'apparaissent plus aux intervenants mais restent dans l'historique.</div>
    <table><tr><th>Logo</th><th>Marque</th><th>Entre parenthèses</th><th>CA séparé pour</th><th>Statut</th><th></th></tr>
    ${A.L.marques.filter(m => +m.actif || A.showHidden).map(m => `<tr><td>${m.logo?`<img class="logo-sm" src="uploads/${m.logo}" alt="">`:`<span class="muted">aucun</span>`}<br><label class="hint" style="cursor:pointer;text-decoration:underline">${m.logo ? "remplacer" : "ajouter"}<input type="file" accept="image/png,image/jpeg,image/webp" data-logo="${m.id}" hidden></label></td>
      <td><input data-bn="${m.id}" value="${esc(m.nom)}"></td><td><input data-bd="${m.id}" value="${esc(m.detail || "")}"></td><td><input data-bs="${m.id}" value="${esc(m.sous_marques || "")}" placeholder="—"></td>
      <td>${+m.actif?`<span class="pill p-ok">Active</span>`:`<span class="pill p-bad">Masquée</span>`}</td>
      <td class="num"><button class="ghost" data-bsave="${m.id}">Enregistrer</button> <button class="ghost" data-btoggle="${m.id}">${+m.actif?"Masquer":"Réactiver"}</button></td></tr>`).join("")}</table>
    <button class="ghost" id="b-hidden" style="margin-top:10px">${A.showHidden ? "Cacher les marques masquées" : "Voir aussi les marques masquées"}</button></div>`;
}
function bMarques(){
  bMcRefs();
  if ($("#cc-save")) $("#cc-save").onclick = async () => { try { await api("concurrents_save", {liste:$("#cc-liste").value}); A.L.concurrence = $("#cc-liste").value.split(/\n+/).map(x => x.trim()).filter(Boolean); toast("Liste enregistrée"); } catch(e) { toast(e.message); } };
  const val = (sel, id) => (document.querySelector(`[${sel}="${id}"]`) || {}).value || "";
  const save = async (fields, file) => { const fd = new FormData(); Object.entries(fields).forEach(([k,v]) => fd.append(k, v)); if (file) fd.append("logo", file); await api("save_marque", fd); await reloadLists(); go("marques"); };
  $("#b-add").onclick = async () => { try { await save({nom:$("#b-nom").value, detail:$("#b-detail").value, sous_marques:$("#b-sous").value}, $("#b-logo").files[0]); toast("Marque ajoutée"); } catch(e) { toast(e.message); } };
  $("#b-hidden").onclick = () => { A.showHidden = !A.showHidden; go("marques"); };
  const full = m => ({id:m.id, nom:val("data-bn", m.id) || m.nom, detail:val("data-bd", m.id), sous_marques:val("data-bs", m.id), actif:m.actif});
  document.querySelectorAll("[data-logo]").forEach(inp => inp.onchange = async () => { const m = A.L.marques.find(x => +x.id === +inp.dataset.logo);
    try { await save(full(m), inp.files[0]); toast("Logo mis à jour"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-bsave]").forEach(b => b.onclick = async () => { const m = A.L.marques.find(x => +x.id === +b.dataset.bsave);
    try { await save(full(m)); toast("Enregistré"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-btoggle]").forEach(b => b.onclick = async () => { const m = A.L.marques.find(x => +x.id === +b.dataset.btoggle); await save(Object.assign(full(m), {actif: +m.actif ? 0 : 1})); });
}

/* ---------- Formations ---------- */
function vForma(){
  const F = A.forma || [], lim = A.flim ? Math.floor(A.flim / 1048576) : 0;
  const mopts = `<option value="">Général (toutes les marques)</option>` + A.L.marques.filter(m => +m.actif).map(m => `<option value="${m.id}">${esc(m.nom)}</option>`).join("");
  const kind = {pdf:"PDF", video:"Vidéo", image:"Image", lien:"Lien", fichier:"Fichier"};
  return `<div class="card accent"><h2>Ajouter un support de formation</h2>
    <div class="muted">Fiches produit, argumentaires, vidéos… Les intervenants les retrouvent dans l'appli, rubrique « Formations », classés par marque. Seules les personnes connectées à l'appli peuvent les ouvrir.</div>
    <div class="row"><label>Marque<select id="f-marque">${mopts}</select></label><label>Titre<input id="f-titre" placeholder="Ex. Fiche produit, nouveau sérum"></label></div>
    <label>Description <span class="hint">facultatif</span><textarea id="f-desc" rows="2" placeholder="Ce qu'il faut retenir, à qui ça sert…"></textarea></label>
    <div class="row"><label>Fichier <span class="hint">PDF, vidéo, image, PowerPoint, Word, Excel${lim ? ` · ${lim} Mo maximum sur votre hébergement` : ""}</span><input id="f-file" type="file" accept=".pdf,.mp4,.m4v,.mov,.webm,.jpg,.jpeg,.png,.gif,.mp3,.m4a,.ppt,.pptx,.doc,.docx,.xls,.xlsx,.zip"></label>
      <label>ou un lien <span class="hint">YouTube, Vimeo, Google Drive…</span><input id="f-url" type="url" placeholder="https://"></label></div>
    <div class="hint">Pour les vidéos lourdes, le plus simple est de les mettre sur YouTube en « non répertoriée » ou sur Vimeo, puis de coller le lien ici.</div>
    ${accesPicker("new", false, [])}
    <button class="primary" id="f-add">Ajouter</button><div id="f-prog" class="hint"></div></div>
    <div class="card tablebox"><h2>${F.length} support${F.length > 1 ? "s" : ""}</h2>${F.length ? `<table><tr><th>Marque</th><th>Support</th><th>Type</th><th>Accès</th><th>Ajouté le</th><th></th></tr>
      ${F.map(f => `<tr><td>${esc(f.marque || "Général")}</td><td><b>${esc(f.titre)}</b>${f.description ? `<br><span class="muted">${esc(f.description)}</span>` : ""}<br><a href="formation.php?id=${f.id}" target="_blank" rel="noopener">${f.type === "lien" ? esc(f.url) : esc(f.nom_original)}</a></td>
        <td><span class="pill">${kind[f.type] || f.type}</span>${f.taille ? `<br><span class="muted">${(f.taille / 1048576).toFixed(1).replace(".", ",")} Mo</span>` : ""}</td><td>${+f.restreint ? `<span class="pill p-warn">${f.acces.length} personne${f.acces.length > 1 ? "s" : ""}</span>` : `<span class="pill p-ok">Toutes</span>`}<br><button class="ghost" data-facc="${f.id}" style="margin-top:4px">Gérer l'accès</button></td>
        <td>${fmtD(f.created_at.slice(0, 10))}</td>
        <td class="num"><button class="ghost danger" data-fdel="${f.id}">Supprimer</button></td></tr>
        <tr data-faccrow="${f.id}" hidden><td colspan="6">${accesPicker(f.id, +f.restreint, f.acces)}<div class="btns"><button class="primary" data-faccsave="${f.id}">Enregistrer l'accès</button></div></td></tr>`).join("")}</table>`
      : `<div class="empty">Aucun support pour l'instant.</div>`}</div>`;
}
/** Choix des intervenants ayant accès à un support (par prénom et nom). */
function accesPicker(key, restreint, ids){
  const set = new Set(ids.map(Number));
  const people = A.L.animatrices.filter(a => +a.actif || set.has(+a.id)).sort(byNom);
  return `<div class="acc" data-acc="${key}">
    <div class="muted" style="margin-bottom:6px">Accès</div>
    <div class="chips"><button type="button" class="chip" data-mode="0" aria-pressed="${!restreint}">Toutes les intervenants</button><button type="button" class="chip" data-mode="1" aria-pressed="${!!restreint}">Certaines personnes</button></div>
    <div class="accbox" ${restreint ? "" : "hidden"}>
      <div class="accsel"></div>
      <input class="accq" placeholder="Tapez un prénom ou un nom, puis Entrée pour cocher" autocomplete="off">
      <div class="hint acccount"></div>
      <div class="acclist">${people.map(a => `<label class="accitem" data-q="${esc((a.prenom + " " + a.nom + " " + a.nom + " " + a.prenom + " " + a.zone).toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, ""))}"><input type="checkbox" value="${a.id}" data-name="${esc(nm(a))}" ${set.has(+a.id) ? "checked" : ""}><span><b>${esc(nm(a))}</b> <span class="muted">· ${esc(a.zone)}${a.created_at ? "" : " · pas encore inscrite"}</span></span></label>`).join("") || `<div class="empty">Aucune personne. Importez une liste dans l'onglet Intervenants.</div>`}</div>
    </div></div>`;
}
function readAcces(key){
  const box = document.querySelector(`[data-acc="${key}"]`);
  return { restreint: box.querySelector('[data-mode="1"]').getAttribute("aria-pressed") === "true", ids: [...box.querySelectorAll(".acclist input:checked")].map(i => +i.value) };
}
function bindAcces(){
  document.querySelectorAll("[data-acc]").forEach(box => {
    const q = box.querySelector(".accq"), list = box.querySelector(".acclist"), sel = box.querySelector(".accsel");
    const refresh = () => {
      const on = [...box.querySelectorAll(".acclist input:checked")];
      box.querySelector(".acccount").textContent = on.length + " personne" + (on.length > 1 ? "s" : "") + " cochée" + (on.length > 1 ? "s" : "");
      sel.innerHTML = on.map(i => `<button type="button" class="accchip" data-un="${i.value}">${esc(i.dataset.name)} <span>✕</span></button>`).join("");
    };
    const filter = () => { const v = q.value.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").trim(); const words = v.split(/\s+/).filter(Boolean);
      box.querySelectorAll(".accitem").forEach(i => i.hidden = words.length && !words.every(w => i.dataset.q.includes(w))); };
    box.querySelectorAll("[data-mode]").forEach(b => b.onclick = () => { box.querySelectorAll("[data-mode]").forEach(x => x.setAttribute("aria-pressed", x === b)); box.querySelector(".accbox").hidden = b.dataset.mode !== "1"; if (b.dataset.mode === "1" && q) q.focus(); });
    if (!q) return;
    q.oninput = filter;
    // Clavier : Entrée coche la première personne trouvée, Échap vide la recherche.
    q.onkeydown = e => {
      if (e.key === "Enter") { e.preventDefault(); const first = [...box.querySelectorAll(".accitem")].find(i => !i.hidden); if (first) { const c = first.querySelector("input"); c.checked = !c.checked; refresh(); q.value = ""; filter(); } }
      if (e.key === "Escape") { q.value = ""; filter(); }
    };
    list.onchange = refresh;
    sel.onclick = e => { const b = e.target.closest("[data-un]"); if (!b) return; const c = box.querySelector(`.acclist input[value="${b.dataset.un}"]`); if (c) c.checked = false; refresh(); };
    refresh();
  });
}
function bForma(){
  bindAcces();
  document.querySelectorAll("[data-facc]").forEach(b => b.onclick = () => { const r = document.querySelector(`[data-faccrow="${b.dataset.facc}"]`); r.hidden = !r.hidden; });
  document.querySelectorAll("[data-faccsave]").forEach(b => b.onclick = async () => { const id = +b.dataset.faccsave, a = readAcces(id);
    try { await api("formation_acces", {id, restreint:a.restreint ? 1 : 0, animatrices:a.ids}); toast("Accès enregistré"); go("formations"); } catch(e) { toast(e.message); } });
  $("#f-add").onclick = async () => {
    const acc = readAcces("new"); if (acc.restreint && !acc.ids.length) return toast("Choisissez au moins un(e) intervenant(e), ou ouvrez le support à toutes.");
    const fd = new FormData(); fd.append("restreint", acc.restreint ? "1" : ""); fd.append("acces", acc.ids.join(",")); fd.append("marque_id", $("#f-marque").value); fd.append("titre", $("#f-titre").value); fd.append("description", $("#f-desc").value); fd.append("url", $("#f-url").value.trim());
    const file = $("#f-file").files[0]; if (file) fd.append("fichier", file);
    if (file && A.flim && file.size > A.flim) return toast("Fichier trop lourd pour l'hébergement (" + Math.floor(A.flim / 1048576) + " Mo maximum). Pour une vidéo, mettez-la sur YouTube ou Vimeo et collez le lien.");
    const b = $("#f-add"); b.disabled = true;
    try {
      // Envoi avec barre de progression (les vidéos peuvent être longues à envoyer)
      await new Promise((res, rej) => { const x = new XMLHttpRequest(); x.open("POST", "api.php?action=save_formation"); x.setRequestHeader("X-Requested-With", "amaya");
        x.upload.onprogress = e => { if (e.lengthComputable) $("#f-prog").textContent = "Envoi : " + Math.round(100 * e.loaded / e.total) + " %"; };
        x.onload = () => { let d = {}; try { d = JSON.parse(x.responseText); } catch(e) {} x.status < 300 ? res(d) : rej(new Error(d.error || "Erreur " + x.status)); };
        x.onerror = () => rej(new Error("Connexion perdue pendant l'envoi.")); x.send(fd); });
      toast("Support ajouté"); go("formations");
    } catch(e) { toast(e.message); b.disabled = false; $("#f-prog").textContent = ""; }
  };
  let armed = 0;
  document.querySelectorAll("[data-fdel]").forEach(b => b.onclick = async () => { const id = +b.dataset.fdel;
    if (armed !== id) { armed = id; b.textContent = "Confirmer"; return; }
    try { await api("delete_formation", {id}); toast("Support supprimé"); go("formations"); } catch(e) { toast(e.message); } });
}

/* ---------- Points d'intervention ---------- */
function vMag(){
  const list = A.L.magasins.filter(m => m.zone === A.magZone);
  return `${typeof vImport === "function" && (A.L.moi || {}).gestion ? vImport("magasins") : ""}${vSecteurs()}<div class="card"><h2>Ajouter un point d'intervention</h2><div class="row3"><label>Zone<select id="g-zone">${zoneOpts(A.magZone)}</select></label><label>Nom complet<input id="g-nom" placeholder="Ex. Galerie Centre Nord, Lille"></label><label>Ville<input id="g-ville"></label><label>Adresse <span class="hint">pour le calcul des km</span><input id="g-adr" placeholder="Ex. 12 rue de la République, 69002 Lyon"></label></div>
    <div class="btns" style="margin-top:0"><button class="ghost" id="g-find" type="button">Trouver l'adresse</button></div><div id="g-sug"></div>
    <label class="ck"><input type="checkbox" id="g-tr"><span>Travel retail (aéroport, duty free)</span></label>
    <button class="primary" id="g-add">Ajouter</button></div>
    <div class="card tablebox"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>Points d'intervention</h2><div style="display:flex;gap:8px;align-items:center"><button class="primary" id="g-all" style="width:auto">Tout enregistrer</button><select id="g-filter" style="width:auto">${zoneOpts(A.magZone)}</select></div></div>
    <div class="plan-test" style="margin:8px 0">Le plus rapide : dans Google Maps, copiez le <b>Plus Code</b> du lieu (ex. <b>9F25+XX Lille</b>) ou ses coordonnées, collez-le directement dans la case Adresse, et faites-le pour tous les magasins. Puis un seul clic sur <b>Tout enregistrer</b> : chaque position est enregistrée comme précise et l'adresse en clair est retrouvée.</div>
    ${list.length ? `<div class="hint" style="margin-bottom:8px">« Trouver l'adresse » propose l'adresse exacte (Base adresse nationale, et recherche par le nom du magasin). Choisir une proposition l'enregistre avec sa position : les km et la prise de poste s'en servent. ${list.filter(m => !+m.geo || +m.geo_approx).length} point${list.filter(m => !+m.geo || +m.geo_approx).length > 1 ? "s" : ""} de vente à vérifier.</div>
    <table><tr><th>Point d'intervention</th><th>Ville</th><th>Adresse (calcul des km)</th><th>Position</th><th>Dép.</th><th>Travel retail</th><th>ZTI</th><th>Statut</th><th></th></tr>
    ${list.map(m => `<tr><td><input data-gn="${m.id}" value="${esc(m.nom)}"></td><td><input data-gv="${m.id}" value="${esc(m.ville)}"></td><td><input data-ga="${m.id}" value="${esc(m.adresse || "")}" placeholder="Rue, rond-point…"></td><td>${!+m.geo ? `<span class="pill">non localisé</span>` : +m.geo_approx ? `<span class="pill p-warn">approximative</span>` : `<span class="pill p-ok">précise</span>`}<div><button class="ghost sm" data-gsug="${m.id}" style="margin-top:4px">Trouver l'adresse</button></div></td><td><input data-gdep="${m.id}" value="${esc(m.dep || "")}" maxlength="3" style="width:56px" title="Département (ex. 75, 2A)"></td><td><input type="checkbox" data-gtr="${m.id}" ${m.circuit === "tr" ? "checked" : ""} style="width:auto"></td><td>${+m.actif?`<span class="pill p-ok">Actif</span>`:`<span class="pill p-bad">Masqué</span>`}</td>
      <td>${+m.zti ? `<span class="pill p-warn">ZTI</span>` : m.zti_sug ? `<span class="hint" title="Seul un périmètre de rues est classé : vérifiez l'arrêté">commune avec une ZTI, à vérifier</span>` : ""}<div><button class="ghost sm" data-gzti="${m.id}">${+m.zti ? "Retirer" : "Classer ZTI"}</button></div></td>
      <td class="num"><button class="ghost" data-gs="${m.id}">Enregistrer</button> <button class="ghost" data-gt="${m.id}">${+m.actif?"Masquer":"Réactiver"}</button></td></tr><tr hidden data-gsugr="${m.id}"><td colspan="9"></td></tr>`).join("")}</table>`
    : `<div class="empty">Aucun point d'intervention pour ${esc(A.magZone)}. Ajoutez-les ci-dessus : les intervenants les choisiront dans la liste.</div>`}</div>`;
}
function vSecteurs(){
  const S = A.secteursEd || (A.secteursEd = (A.L.secteurs || []).map(x => ({nom:x.nom, deps:x.deps.join(", ")})));
  return `<div class="card"><h2>Secteurs</h2><div class="muted">Regroupez des départements pour filtrer les comptes rendus et les rapports (ex. « Hauts-de-France » : 59, 62, 80, 02, 60). Chaque point d'intervention est rattaché à son département, d'après le code postal de son adresse.</div>
    ${S.map((x, i) => `<div class="row3"><label>Nom du secteur<input data-snom="${i}" value="${esc(x.nom)}" placeholder="Ex. Grand Ouest"></label><label>Départements<input data-sdep="${i}" value="${esc(x.deps)}" placeholder="Ex. 44, 49, 85, 35"></label><div style="align-self:end"><button type="button" class="ghost" data-sdel="${i}">Retirer</button></div></div>`).join("")}
    <div class="btns"><button type="button" class="ghost" id="s-add">+ Ajouter un secteur</button><button type="button" class="primary" id="s-save">Enregistrer les secteurs</button></div></div>`;
}
function bMag(){
  const sRead = () => { A.secteursEd = A.secteursEd.map((x, i) => ({nom:$(`[data-snom="${i}"]`).value, deps:$(`[data-sdep="${i}"]`).value})); };
  $("#s-add").onclick = () => { sRead(); A.secteursEd.push({nom:"", deps:""}); go("magasins"); };
  document.querySelectorAll("[data-sdel]").forEach(b => b.onclick = () => { sRead(); A.secteursEd.splice(+b.dataset.sdel, 1); go("magasins"); });
  $("#s-save").onclick = async () => { sRead(); try { const r = await api("secteurs_save", {secteurs:A.secteursEd.map(x => ({nom:x.nom.trim(), deps:x.deps.split(/[\s,;]+/).filter(Boolean)}))}); A.L.secteurs = r.secteurs; A.secteursEd = null; toast(r.secteurs.length + " secteur(s) enregistré(s)"); go("magasins"); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-gdep]").forEach(i => i.onchange = async () => { try { await api("magasin_dep", {id:+i.dataset.gdep, dep:i.value.trim()}); const m = A.L.magasins.find(x => +x.id === +i.dataset.gdep); if (m) m.dep = i.value.trim().toUpperCase(); toast("Département enregistré"); } catch(e) { toast(e.message); } });
  if (typeof bImport === "function") bImport("magasins", async () => { await reloadLists(); go("magasins"); });
  const save = async (m) => { await api("save_magasin", m); await reloadLists(); go("magasins"); };
  $("#g-filter").onchange = e => { A.magZone = e.target.value; go("magasins"); };
  // Ajout : recherche de l'adresse exacte avant d'enregistrer (par le nom du magasin et la ville). La position choisie est enregistrée avec le point.
  $("#g-find").onclick = async () => { const nom = $("#g-nom").value.trim(), ville = $("#g-ville").value.trim(); if (!nom) return toast("Indiquez d'abord le nom complet du point d'intervention.");
    const box = $("#g-sug"); box.innerHTML = `<span class="hint">Recherche…</span>`; A.addPos = null;
    try { const r = await api("magasin_suggest", {nom, ville, adresse:$("#g-adr").value, zone:$("#g-zone").value});
      box.innerHTML = (r.suggestions.length ? `<div class="gsug">${r.suggestions.map((x, i) => `<button type="button" class="ghost sm" data-gfx="${i}" style="text-align:left"><b>${esc(x.adresse)}</b>, ${esc(x.cp)} ${esc(x.ville)} <span class="hint">· ${esc(x.source)}</span></button>`).join("")}</div>`
        : `<div class="hint">Rien trouvé${ville ? "" : " : ajoutez la ville"}. Vous pouvez aussi coller un Plus Code ou des coordonnées Google Maps dans la case Adresse.</div>`)
        + (r.geoapify ? `<div class="hint">Recherche : <a href="https://www.geoapify.com/" target="_blank" rel="noopener">Powered by Geoapify</a> · © OpenStreetMap</div>` : "");
      box.querySelectorAll("[data-gfx]").forEach(b => b.onclick = () => { const x = r.suggestions[+b.dataset.gfx]; $("#g-adr").value = [x.adresse, [x.cp, x.ville].filter(Boolean).join(" ")].filter(Boolean).join(", ");
        if (x.ville) $("#g-ville").value = x.ville; A.addPos = x.lat && x.lng ? {lat:x.lat, lng:x.lng} : null; box.innerHTML = `<div class="hint">Adresse retenue${A.addPos ? ", position enregistrée à l'ajout" : ""}.</div>`; });
    } catch(e) { box.innerHTML = `<span class="hint">${esc(e.message)}</span>`; } };
  $("#g-add").onclick = async () => { try { A.magZone = $("#g-zone").value;
      const r = await api("save_magasin", {zone:A.magZone, nom:$("#g-nom").value, ville:$("#g-ville").value, adresse:$("#g-adr").value, circuit:$("#g-tr").checked ? "tr" : ""});
      if (A.addPos && r.id && !r.position) await api("magasin_position", {id:r.id, ...A.addPos}); A.addPos = null;
      await reloadLists(); go("magasins"); toast("Point d'intervention ajouté"); } catch(e) { toast(e.message); } };
  $("#g-all").onclick = async () => { const b = $("#g-all"), L = A.L.magasins.filter(m => m.zone === A.magZone); let n = 0, p = 0, err = [];
    const ch = L.filter(m => $(`[data-gn="${m.id}"]`) && ($(`[data-gn="${m.id}"]`).value !== m.nom || $(`[data-gv="${m.id}"]`).value !== m.ville || $(`[data-ga="${m.id}"]`).value !== (m.adresse || "") || $(`[data-gtr="${m.id}"]`).checked !== (m.circuit === "tr")
      || ((!+m.geo || +m.geo_approx) && /[23456789CFGHJMPQRVWX]{4,8}\+|^\s*-?\d{1,2}\.\d+\s*[,; ]|google\.|goo\.gl/i.test($(`[data-ga="${m.id}"]`).value)))); // Plus Code ou coordonnées déjà saisis mais pas encore localisés
    if (!ch.length) return toast("Rien de modifié.");
    b.disabled = true;
    for (const m of ch) { b.textContent = "Enregistrement " + (++n) + "/" + ch.length + "…";
      try { const r = await api("save_magasin", {id:m.id, zone:m.zone, enseigne:m.enseigne, nom:$(`[data-gn="${m.id}"]`).value, ville:$(`[data-gv="${m.id}"]`).value, adresse:$(`[data-ga="${m.id}"]`).value, actif:m.actif, circuit:$(`[data-gtr="${m.id}"]`).checked ? "tr" : ""}); if (r.position) p++; }
      catch(e) { err.push(m.nom + " : " + e.message); } }
    await reloadLists(); toast(ch.length - err.length + " enregistré(s), dont " + p + " position(s) précise(s)" + (err.length ? " · erreurs : " + err.join(" ; ") : "")); go("magasins"); };
  document.querySelectorAll("[data-gs]").forEach(b => b.onclick = async () => { const m = A.L.magasins.find(x => +x.id === +b.dataset.gs);
    try { await save({id:m.id, zone:m.zone, enseigne:m.enseigne, nom:$(`[data-gn="${m.id}"]`).value, ville:$(`[data-gv="${m.id}"]`).value, adresse:$(`[data-ga="${m.id}"]`).value, actif:m.actif, circuit:$(`[data-gtr="${m.id}"]`).checked ? "tr" : ""}); toast("Enregistré"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-gsug]").forEach(b => b.onclick = async () => { const m = A.L.magasins.find(x => +x.id === +b.dataset.gsug), tr = document.querySelector(`[data-gsugr="${m.id}"]`), td = tr.firstElementChild;
    tr.hidden = false; td.innerHTML = `<span class="hint">Recherche…</span>`;
    try { const r = await api("magasin_suggest", {nom:$(`[data-gn="${m.id}"]`).value, adresse:$(`[data-ga="${m.id}"]`).value, ville:$(`[data-gv="${m.id}"]`).value, zone:m.zone});
      td.innerHTML = (r.suggestions.length ? `<div class="gsug">${r.suggestions.map((x, i) => `<button class="ghost sm" data-gsx="${i}" style="text-align:left"><b>${esc(x.adresse)}</b>, ${esc(x.cp)} ${esc(x.ville)} <span class="hint">· ${esc(x.source)}${x.score != null ? " · fiabilité " + Math.round(x.score * 100) + " %" : ""}</span></button>`).join("")}</div>`
        : `<div class="hint">Rien trouvé par le nom. Utilisez Google Maps ci-dessous.</div>`)
        + (r.geoapify ? `<div class="hint">Recherche : <a href="https://www.geoapify.com/" target="_blank" rel="noopener">Powered by Geoapify</a> · © OpenStreetMap</div>` : "")
        + `<div class="gcoll"><a class="btn ghost sm" href="${esc(r.google)}" target="_blank" rel="noopener">Ouvrir dans Google Maps</a>
          <input data-gcol="${m.id}" placeholder="Collez ici les coordonnées, le lien ou le Plus Code (ex. 9F25+XX Lille)"><button class="primary sm" data-gcolok="${m.id}">Enregistrer la position</button><button class="ghost sm" data-gsx="x">Fermer</button></div>
          <div class="hint">Dans Google Maps : trouvez le magasin, faites un clic droit dessus, cliquez sur les chiffres en haut du menu (ex. -20.8823, 55.4504 : ils sont copiés), puis collez-les ici. Le bouton « Partager » › « Copier le lien » marche aussi.</div>`;
      td.querySelector("[data-gcolok]").onclick = async () => { const v = td.querySelector("[data-gcol]").value.trim(); if (!v) return toast("Collez les coordonnées ou le lien.");
        try { const p = await api("magasin_coller", {id:m.id, texte:v}); await reloadLists(); toast("Position enregistrée" + (p.adresse ? " : " + p.adresse + ", " + p.cp + " " + p.ville : "")); go("magasins"); } catch(e) { toast(e.message); } };
      td.querySelectorAll("[data-gsx]").forEach(c => c.onclick = async () => { if (c.dataset.gsx === "x") { tr.hidden = true; return; } const x = r.suggestions[+c.dataset.gsx];
        try { await api("save_magasin", {id:m.id, zone:m.zone, enseigne:m.enseigne, nom:$(`[data-gn="${m.id}"]`).value, ville:x.ville || $(`[data-gv="${m.id}"]`).value, adresse:x.adresse, actif:m.actif, circuit:m.circuit});
          if (x.lat && x.lng) await api("magasin_position", {id:m.id, lat:x.lat, lng:x.lng}); await reloadLists(); toast("Adresse enregistrée"); go("magasins"); } catch(e) { toast(e.message); } });
    } catch(e) { td.innerHTML = `<span class="hint">${esc(e.message)}</span>`; } });
  document.querySelectorAll("[data-gzti]").forEach(b => b.onclick = async () => { const m = A.L.magasins.find(x => +x.id === +b.dataset.gzti);
    try { await api("magasin_zti", {id:m.id, zti:+m.zti ? 0 : 1}); await reloadLists(); toast(+m.zti ? "Retiré des ZTI" : "Classé en ZTI : soirées 21 h - minuit payées double"); go("magasins"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-gt]").forEach(b => b.onclick = async () => { const m = A.L.magasins.find(x => +x.id === +b.dataset.gt); await save({id:m.id, zone:m.zone, enseigne:m.enseigne, nom:m.nom, ville:m.ville, adresse:m.adresse || "", circuit:m.circuit, actif: +m.actif ? 0 : 1}); });
}

/* ---------- Envois ---------- */
function vEnvois(){
  return `<div class="card tablebox"><h2>Historique des envois</h2>${A.L.envois.length ? `<table><tr><th>Envoyé le</th><th>Période</th><th>Rapport</th><th>Destinataires</th><th>Par</th><th>Statut</th></tr>
    ${A.L.envois.map(e => `<tr><td>${esc(e.created_at)}</td><td>${fmtD(e.date_debut)} au ${fmtD(e.date_fin)}</td><td>${esc(e.marques)}</td><td>${esc(e.destinataire).replace(/, /g, "<br>")}</td><td>${esc(e.par || "")}</td><td>${e.statut==="envoyé"?`<span class="pill p-ok">Envoyé</span>`:e.statut==="démo"?`<span class="pill">Démo, non envoyé</span>`:`<span class="pill p-bad">Échec</span>`}</td></tr>`).join("")}</table>`
    : `<div class="empty">Les comptes rendus envoyés par e-mail apparaîtront ici.</div>`}</div>${vListes()}`;
}
{ const l = document.getElementById("logo-home"); if (l) l.onclick = () => { if (A.L) toDash(); }; }
boot();

/* ---------- Rapports d'événement (accueil et hôtessariat) ---------- */
const EV = { f:{from:"", to:"", statut:""}, L:[], sel:null, d:null };
async function loadEvts(){ EV.L = (await api("evts", null, new URLSearchParams(EV.f).toString())).evts; EV.d = EV.sel ? await api("evt", null, "id=" + EV.sel) : null; }
function vEvts(){
  const f = EV.f, nb = v => v == null || v === "" ? "–" : (+v).toLocaleString("fr-FR");
  return `<div class="card"><h2>Comptes rendus d'événement</h2><div class="muted">Rédigés dans l'appli par le chef d'équipe (ou chaque hôte et hôtesse) après chaque journée d'accueil, de salon, de soirée ou de street marketing. Relisez, complétez les recommandations, validez : le client les voit alors dans son espace et peut télécharger le PDF.</div>
      <div class="row3"><label>Du<input type="date" id="ev-from" value="${f.from}"></label><label>Au<input type="date" id="ev-to" value="${f.to}"></label><label>Validation<select id="ev-st"><option value="">Tous</option><option value="avalider" ${f.statut === "avalider" ? "selected" : ""}>À valider</option><option value="valide" ${f.statut === "valide" ? "selected" : ""}>Validés</option></select></label></div>
      <div class="btns"><button class="primary" id="ev-go">Afficher</button></div></div>
    ${EV.d ? vEvtDetail() : ""}
    <div class="card tablebox"><h2>${EV.L.length} rapport${EV.L.length > 1 ? "s" : ""}</h2>${EV.L.length ? `<div class="tablewrap"><table><tr><th>Statut</th><th>Date</th><th>Événement</th><th>Lieu</th><th>Rédigé par</th><th class="num">Visiteurs</th><th class="num">Contacts</th><th class="num">Photos</th></tr>
      ${EV.L.map(e => `<tr class="click" data-evid="${e.id}"><td>${e.valide_le ? `<span class="pill ok">Validé</span>` : `<span class="pill warn">À valider</span>`}</td><td>${fmtD(e.date_evt)}</td><td><b>${esc(e.evenement || e.type || "")}</b><div class="hint">${esc(e.client || "")}</div></td>
        <td>${esc(e.lieu)}</td><td>${esc(e.auteur)}${e.role ? `<div class="hint">${esc(e.role)}</div>` : ""}</td><td class="num">${nb(e.visiteurs)}</td><td class="num">${nb(e.contacts)}</td><td class="num">${e.nb_photos}</td></tr>`).join("")}</table></div>`
      : `<div class="empty">Aucun rapport d'événement sur cette période. Ils arrivent ici dès qu'une hôtesse l'envoie depuis son appli.</div>`}</div>`;
}
function vEvtDetail(){
  const E = EV.d.evt, C = EV.d.champs, ok = can("rapports");
  return `<div class="card accent"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><h2>${esc(E.evenement || E.type || "Événement")}</h2><div class="hint">${esc(E.client)} · ${fmtD(E.date_evt)} · ${esc(E.lieu)} · par ${esc(E.auteur)}${E.role ? " (" + esc(E.role) + ")" : ""}</div></div>
      <div class="btns" style="margin:0"><a class="btn" target="_blank" rel="noopener" href="api.php?action=evt_pdf&id=${E.id}">PDF</a><button class="ghost" id="evd-x">Fermer</button></div></div>
    ${E.equipe_jour.length ? `<div class="hint">Équipe : ${E.equipe_jour.map(x => esc(x.nom) + (x.role ? " (" + esc(x.role) + ")" : "")).join(" · ")}</div>` : ""}
    <div class="row3"><label>Affluence<select data-ed="affluence" ${ok ? "" : "disabled"}>${EV.d.affluence.map(a => `<option ${E.affluence === a ? "selected" : ""}>${esc(a)}</option>`).join("")}</select></label>
      <label>Visiteurs accueillis<input data-ed="visiteurs" value="${E.visiteurs ?? ""}" ${ok ? "" : "disabled"}></label><label>Contacts collectés<input data-ed="contacts" value="${E.contacts ?? ""}" ${ok ? "" : "disabled"}></label></div>
    <div class="row3"><label>Documents ou cadeaux remis<input data-ed="documents" value="${E.documents ?? ""}" ${ok ? "" : "disabled"}></label><span></span><span></span></div>
    ${Object.entries(C).map(([k, [l]]) => `<label>${esc(l)}<textarea data-ed="${k}" rows="3" ${ok ? "" : "disabled"}>${esc(E[k] || "")}</textarea></label>`).join("")}
    <label>Recommandations au client <span class="hint">proposées d'après le rapport, à relire et compléter : elles figurent dans le PDF</span><textarea data-ed="recommandations" rows="5" ${ok ? "" : "disabled"}>${esc(E.recommandations || EV.d.reco_auto || "")}</textarea></label>
    ${E.photos.length ? `<div class="muted" style="margin-top:6px">Photos : décochez celles que le client ne doit pas voir.</div><div class="ph-grid">${E.photos.map(p => `<label class="ph-it"><img src="${p.url}" alt=""><span><input type="checkbox" data-edph="${p.id}" ${+p.client ? "checked" : ""} ${ok ? "" : "disabled"}> visible</span></label>`).join("")}</div>` : ""}
    ${ok ? `<div class="btns"><button class="ghost" id="evd-save">Enregistrer</button>${E.valide_le ? `<button class="ghost" id="evd-unval">Retirer la validation</button>` : `<button class="primary" id="evd-val">Enregistrer et valider</button>`}</div>` : ""}</div>`;
}
function bEvts(){
  $("#ev-go").onclick = () => { EV.f = {from:$("#ev-from").value, to:$("#ev-to").value, statut:$("#ev-st").value}; go("rapports"); };
  document.querySelectorAll("[data-evid]").forEach(tr => tr.onclick = () => { EV.sel = +tr.dataset.evid; go("rapports").then(() => scrollTo(0, 0)); });
  if (!EV.d) return;
  $("#evd-x").onclick = () => { EV.sel = null; go("rapports"); };
  const save = async (valide) => { const body = {id:EV.d.evt.id, photos_client:{}}; document.querySelectorAll("[data-ed]").forEach(i => body[i.dataset.ed] = i.value);
    document.querySelectorAll("[data-edph]").forEach(c => body.photos_client[c.dataset.edph] = c.checked ? 1 : 0); if (valide !== undefined) body.valide = valide;
    try { await api("evt_save", body); toast(valide ? "Validé : visible par le client" : valide === 0 ? "Validation retirée" : "Enregistré"); go("rapports"); } catch(e) { toast(e.message); } };
  if ($("#evd-save")) $("#evd-save").onclick = () => save();
  if ($("#evd-val")) $("#evd-val").onclick = () => save(1);
  if ($("#evd-unval")) $("#evd-unval").onclick = () => save(0);
}

/* ---------- Comptes rendus : animation commerciale ou événementiel ---------- */
function crTog(){
  const n = (A.L.evts_a_valider || 0);
  return `<div class="card cr-tog"><div class="muted">Tout ce que le terrain envoie depuis l'appli, à relire et valider avant tout envoi au client.</div>
    <div class="seg" id="cr-tog"><button type="button" data-crt="anim" aria-pressed="${!A.crt || A.crt === "anim"}">Animation commerciale</button><button type="button" data-crt="evt" aria-pressed="${A.crt === "evt"}">Événementiel et hôtessariat</button><button type="button" data-crt="mc" aria-pressed="${A.crt === "mc"}">Merchandising</button></div></div>`;
}
function bCrTog(){ document.querySelectorAll("[data-crt]").forEach(b => b.onclick = () => { A.crt = b.dataset.crt; EV.sel = null; go("rapports"); }); }

/* ---------- Rapports événementiels : le bilan d'une opération envoyé au client ---------- */
const ER = { from:"", to:"", ops:[], id:0, B:null, sel:null, reco:null, to_:"", msg:"", jours:false };
async function loadEvtRap(){
  if (!ER.from) { const d = new Date(); d.setMonth(d.getMonth() - 3); ER.from = d.toISOString().slice(0, 10); ER.to = new Date().toISOString().slice(0, 10); }
  ER.ops = (await api("evt_ops", null, new URLSearchParams({from:ER.from, to:ER.to}).toString())).ops;
  if (ER.id && !ER.ops.some(o => +o.id === ER.id)) ER.id = 0;
  ER.B = ER.id ? await api("evt_bilan", null, "devis_id=" + ER.id) : null;
  if (ER.B && !ER.sel) { ER.sel = new Set(ER.B.evts.filter(e => e.valide_le).map(e => +e.id)); ER.reco = null; ER.to_ = ER.B.op.emails || ""; }
}
function vEvtRap(){
  const nb = v => v == null || v === "" ? "–" : (+v).toLocaleString("fr-FR"), B = ER.B;
  const head = `<div class="card accent"><h2>Rapports événementiels</h2><div class="muted">Le bilan d'une opération d'accueil ou d'hôtessariat, toutes journées confondues : chiffres, équipe mobilisée, recommandations, comptes rendus du terrain et photos. Vous le relisez, vous l'envoyez au client en PDF.</div>
    <div class="row3"><label>Du<input type="date" id="er-from" value="${ER.from}"></label><label>Au<input type="date" id="er-to" value="${ER.to}"></label><span></span></div></div>
    <div class="card tablebox"><h2>${ER.ops.length} opération${ER.ops.length > 1 ? "s" : ""} avec des comptes rendus</h2>${ER.ops.length ? `<div class="tablewrap"><table><tr><th>Opération</th><th>Client</th><th>Dates</th><th class="num">Journées</th><th class="num">Visiteurs</th><th class="num">Contacts</th><th></th></tr>
      ${ER.ops.map(o => `<tr class="click${+o.id === ER.id ? " sel" : ""}" data-erop="${o.id}"><td><b>${esc(o.objet || o.type_op || "")}</b><div class="hint">${esc(o.type_op || "")}</div></td><td>${esc(o.client || "")}</td><td>${fmtD(o.du)}${o.au !== o.du ? " au " + fmtD(o.au) : ""}</td>
        <td class="num">${o.n}${+o.nv < +o.n ? `<div class="hint" style="color:var(--terra)">${o.n - o.nv} à valider</div>` : ""}</td><td class="num">${nb(o.visiteurs)}</td><td class="num">${nb(o.contacts)}</td><td><span class="linkbtn">Préparer le rapport ›</span></td></tr>`).join("")}</table></div>`
      : `<div class="empty">Aucun compte rendu d'événement sur cette période.</div>`}</div>`;
  if (!B) return head;
  const sel = ER.sel, S = B.evts.filter(e => sel.has(+e.id)), sum = k => S.reduce((a, e) => a + (+e[k] || 0), 0), O = B.op;
  const reco = ER.reco !== null ? ER.reco : B.reco_auto;
  const q = () => new URLSearchParams({devis_id:O.id, ids:[...sel].join(","), reco:$("#er-reco") ? $("#er-reco").value : reco}).toString();
  return head + `<div class="card accent" id="er-b"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><h2>${esc(O.objet || O.type)}</h2><div class="hint">${esc(O.client)}${O.type ? " · " + esc(O.type) : ""}${O.numero ? " · " + esc(O.numero) : ""}</div></div><button class="ghost sm" id="er-x">Fermer</button></div>
    <div class="kpis3"><div class="kpi"><b>${S.length}</b><span>journée${S.length > 1 ? "s" : ""} retenue${S.length > 1 ? "s" : ""}</span></div><div class="kpi"><b>${B.tot.personnes}</b><span>personnes mobilisées</span></div><div class="kpi"><b>${nb(sum("visiteurs"))}</b><span>visiteurs accueillis</span></div><div class="kpi"><b>${nb(sum("contacts"))}</b><span>contacts collectés</span></div></div>
    ${B.equipe.length ? `<div class="muted" style="margin:10px 0 4px"><b>Équipe</b></div><div class="eq">${B.equipe.map(x => `<div class="eq-r"><b>${esc(x.role)}</b> <span class="pill">${x.noms.length}</span> <span class="hint">${esc(x.noms.join(", "))}</span></div>`).join("")}</div>` : ""}
    <div class="muted" style="margin:12px 0 4px"><b>Journées à inclure</b> <span class="hint">· seuls les comptes rendus validés sont cochés au départ</span></div>
    <div class="tablewrap"><table><tr><th></th><th>Date</th><th>Lieu</th><th>Rédigé par</th><th>Affluence</th><th class="num">Visiteurs</th><th class="num">Contacts</th><th class="num">Photos</th><th></th></tr>
      ${B.evts.map(e => `<tr><td><input type="checkbox" data-erc="${e.id}" ${sel.has(+e.id) ? "checked" : ""}></td><td>${fmtD(e.date_evt)}</td><td>${esc(e.lieu)}</td><td>${esc(e.auteur)}${e.role ? `<div class="hint">${esc(e.role)}</div>` : ""}</td><td>${esc(e.affluence || "")}</td>
        <td class="num">${nb(e.visiteurs)}</td><td class="num">${nb(e.contacts)}</td><td class="num">${e.photos}</td><td>${e.valide_le ? `<span class="pill ok">Validé</span>` : `<button class="ghost sm" data-erval="${e.id}">À valider ›</button>`}</td></tr>`).join("")}</table></div>
    <label style="margin-top:12px">Recommandations au client <span class="hint">· proposées d'après les comptes rendus : modifiez, complétez ou effacez (un tiret seul pour ne rien mettre). Une ligne par recommandation.</span><textarea id="er-reco" rows="6">${esc(reco)}</textarea></label>
    <div class="btns"><a class="btn" id="er-pdf" target="_blank" rel="noopener" href="api.php?action=evt_bilan_pdf&${q()}">Voir le rapport (PDF)</a><button class="ghost" id="er-re">Proposer à nouveau</button></div>
    ${can("envoi") ? `<div class="muted" style="margin:14px 0 4px"><b>Envoyer au client</b></div><div class="row"><label>Destinataires <span class="hint">· séparez par des virgules</span><input id="er-dest" value="${esc(ER.to_)}" placeholder="contact@client.fr"></label></div>
      <label>Message <span class="hint">· facultatif</span><textarea id="er-msg" rows="4" placeholder="Bonjour, veuillez trouver ci-joint le rapport de l'opération…">${esc(ER.msg)}</textarea></label>
      <label class="ck"><input type="checkbox" id="er-jours" ${ER.jours ? "checked" : ""}><span>Joindre aussi le compte rendu de chaque journée (un PDF par jour)</span></label>
      <div class="btns"><button class="primary" id="er-send">Envoyer par e-mail</button></div>` : ""}</div>`;
}
function bEvtRap(){
  const re = () => go("evts");
  ["#er-from", "#er-to"].forEach(k => $(k).onchange = () => { ER.from = $("#er-from").value; ER.to = $("#er-to").value; re(); });
  document.querySelectorAll("[data-erop]").forEach(tr => tr.onclick = () => { ER.id = +tr.dataset.erop; ER.sel = null; ER.msg = ""; re().then(() => $("#er-b") && $("#er-b").scrollIntoView({behavior:"smooth", block:"start"})); });
  if (!ER.B) return;
  const keep = () => { ER.reco = $("#er-reco").value; if ($("#er-dest")) { ER.to_ = $("#er-dest").value; ER.msg = $("#er-msg").value; ER.jours = $("#er-jours").checked; } };
  $("#er-x").onclick = () => { ER.id = 0; ER.B = null; ER.sel = null; re(); };
  document.querySelectorAll("[data-erc]").forEach(c => c.onchange = () => { keep(); c.checked ? ER.sel.add(+c.dataset.erc) : ER.sel.delete(+c.dataset.erc); re(); });
  document.querySelectorAll("[data-erval]").forEach(b => b.onclick = () => { A.crt = "evt"; EV.sel = +b.dataset.erval; go("rapports").then(() => scrollTo(0, 0)); });
  $("#er-re").onclick = () => { ER.reco = null; re(); };
  $("#er-pdf").onclick = () => { keep(); $("#er-pdf").href = "api.php?action=evt_bilan_pdf&" + new URLSearchParams({devis_id:ER.B.op.id, ids:[...ER.sel].join(","), reco:ER.reco}).toString(); };
  if ($("#er-send")) $("#er-send").onclick = async () => { keep(); const b = $("#er-send"); b.disabled = true;
    try { const r = await api("evt_bilan_send", {devis_id:ER.B.op.id, ids:[...ER.sel], reco:ER.reco, to:ER.to_, message:ER.msg, jours:ER.jours ? 1 : 0}); toast(r.message); try { const L = await api("admin_lists"); A.L.envois = L.envois; } catch(_) {} } catch(e) { toast(e.message); } b.disabled = false; };
}

/* ---------- Merchandising : visites (Comptes rendus) ---------- */
const MC = { f:{from:"", to:"", statut:"", marque:""}, L:[], sel:null, d:null, vsel:new Set() };
async function loadMc(){ MC.L = (await api("mc_visites", null, new URLSearchParams(MC.f).toString())).visites; MC.d = MC.sel ? await api("mc_visite", null, "id=" + MC.sel) : null; }
function vMc(){
  const f = MC.f, av = MC.L.filter(v => !v.valide_le);
  return `<div class="card"><h2>Visites de merchandising</h2><div class="muted">Saisies dans l'appli à chaque point d'intervention : photos avant/après, relevés par référence, linéaire, promotion, PLV, travaux réalisés. Relisez, corrigez, décochez les photos à ne pas montrer, validez : la visite entre dans les rapports merchandising et l'espace de la marque.</div>
      <div class="row3"><label>Du<input type="date" id="mc-from" value="${f.from}"></label><label>Au<input type="date" id="mc-to" value="${f.to}"></label><label>Marque<select id="mc-mq"><option value="">Toutes</option>${A.L.marques.map(m => `<option value="${m.id}" ${+f.marque === +m.id ? "selected" : ""}>${esc(m.nom)}</option>`).join("")}</select></label></div>
      <div class="row3"><label>Validation<select id="mc-st"><option value="">Toutes</option><option value="avalider" ${f.statut === "avalider" ? "selected" : ""}>À valider</option><option value="valide" ${f.statut === "valide" ? "selected" : ""}>Validées</option></select></label><span></span><div class="btns" style="align-self:end;margin:0"><button class="primary" id="mc-go">Afficher</button></div></div></div>
    ${MC.d ? vMcDetail() : ""}
    <div class="card tablebox"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h2>${MC.L.length} visite${MC.L.length > 1 ? "s" : ""}</h2>${av.length && can("rapports") ? `<div class="btns" style="margin:0"><button class="ghost" id="mc-all">Cocher les ${av.length} à valider</button><button class="primary" id="mc-val">Valider la sélection</button></div>` : ""}</div>
      ${MC.L.length ? `<div class="tablewrap"><table class="mc-tab"><tr><th></th><th>Statut</th><th>Date</th><th>Point d'intervention</th><th>Marque</th><th>Par</th><th class="num">Réf.</th><th class="num">Ruptures</th><th class="num">Non réf.</th><th class="num">Travaux</th><th class="num">Photos</th></tr>
      ${MC.L.map(v => `<tr class="click" data-mcid="${v.id}"><td>${v.valide_le ? "" : `<input type="checkbox" class="val-ck" data-mcck="${v.id}" ${MC.vsel.has(+v.id) ? "checked" : ""}>`}</td><td>${v.valide_le ? `<span class="pill ok">Validée</span>` : `<span class="pill warn">À valider</span>`}</td><td>${fmtD(v.date_visite)}<div class="hint">${esc(v.arrivee || "")}${v.depart ? " à " + esc(v.depart) : ""}</div></td>
        <td><b>${esc(v.magasin)}</b><div class="hint">${esc(v.ville || "")}</div></td><td>${esc(v.marque || "")}</td><td>${esc(v.auteur)}</td><td class="num">${v.refs}</td><td class="num ${v.ruptures ? "red" : ""}">${v.ruptures}</td><td class="num ${v.absents ? "red" : ""}">${v.absents}</td><td class="num">${v.taches}</td><td class="num">${v.nb_photos}</td></tr>`).join("")}</table></div>`
      : `<div class="empty">Aucune visite sur cette période. Elles arrivent ici dès qu'un(e) merchandiseur(se) l'envoie depuis l'appli.</div>`}</div>`;
}
function vMcDetail(){
  const V = MC.d.v, D = V.data, ok = can("rapports"), R = MC.d.refs, ro = ok ? "" : "disabled", byR = {}; (D.refs || []).forEach(r => byR[r.id] = r);
  const ph = slot => V.photos.filter(p => p.slot === slot).map(p => `<label class="ph-it"><a href="${p.full}" target="_blank" rel="noopener"><img src="${p.url}" alt=""></a><span><input type="checkbox" data-mcph="${p.id}" ${+p.client ? "checked" : ""} ${ro}> visible</span></label>`).join("") || `<div class="hint">Aucune.</div>`;
  const yes = (k, l) => `<label class="ck"><input type="checkbox" data-mcd="${k}" ${+D[k] ? "checked" : ""} ${ro}><span>${l}</span></label>`;
  return `<div class="card accent"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><h2>${esc(V.magasin)}</h2><div class="hint">${fmtD(V.date_visite)} · ${esc(V.marque || "")} · ${esc(V.operation || "")} · par ${esc(V.auteur)}${V.arrivee ? " · de " + esc(V.arrivee) + " à " + esc(V.depart) : ""}${V.duree != null ? " (" + Math.floor(V.duree / 60) + "h" + String(V.duree % 60).padStart(2, "0") + ")" : ""}</div></div>
      <div class="btns" style="margin:0"><button class="ghost" id="mcd-x">Fermer</button></div></div>
    <div class="mc-aa"><div><h3>Avant</h3><div class="ph-grid">${ph("avant")}</div></div><div><h3>Après</h3><div class="ph-grid">${ph("apres")}</div></div></div>
    ${R.length ? `<div class="muted" style="margin:12px 0 4px"><b>Relevé des références</b></div><div class="tablewrap"><table><tr><th>Référence</th><th>Statut</th><th class="num">Prix relevé</th><th class="num">Conseillé</th><th class="num">Facings</th><th>Étiquette</th><th>Promo</th></tr>
      ${R.map(r => { const x = byR[r.id] || {}; return `<tr><td><b>${esc(r.nom)}</b><div class="hint">${esc([r.gamme, r.ean].filter(Boolean).join(" · "))}</div></td>
        <td><select data-mcrs="${r.id}" ${ro}><option value="">–</option>${Object.entries(MC.d.statuts).map(([k, l]) => `<option value="${k}" ${x.statut === k ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></td>
        <td class="num"><input data-mcrv="${r.id}" data-k="prix" value="${x.prix == null ? "" : String(x.prix).replace(".", ",")}" style="width:80px" ${ro}></td><td class="num">${r.prix == null ? "–" : String(r.prix).replace(".", ",") + " €"}</td>
        <td class="num"><input data-mcrv="${r.id}" data-k="facings" value="${x.facings ?? ""}" style="width:60px" ${ro}></td><td><input type="checkbox" data-mcrc="${r.id}" data-k="etiquette" ${+x.etiquette ? "checked" : ""} ${ro}></td>
        <td><input type="checkbox" data-mcrc="${r.id}" data-k="promo" ${+x.promo ? "checked" : ""} ${ro}> <input data-mcrv="${r.id}" data-k="promo_txt" value="${esc(x.promo_txt || "")}" placeholder="mécanique" style="width:110px" ${ro}></td></tr>`; }).join("")}</table></div>` : ""}
    ${(D.inconnus || []).length || D.scannes ? `<div class="hint" style="margin-top:6px">${D.scannes ? D.scannes + " produit" + (D.scannes > 1 ? "s" : "") + " scanné" + (D.scannes > 1 ? "s" : "") + " au gencod" : ""}${(D.inconnus || []).length ? `${D.scannes ? " · " : ""}gencods hors liste notés : <b>${D.inconnus.map(esc).join(", ")}</b> (à ajouter aux références suivies si besoin)` : ""}</div>` : ""}
    <div class="row3"><label>Linéaire marque (cm)<input data-mcd="lineaire_marque" value="${D.lineaire_marque ?? ""}" ${ro}></label><label>Linéaire total (cm)<input data-mcd="lineaire_total" value="${D.lineaire_total ?? ""}" ${ro}></label><label>Tablettes<input data-mcd="tablettes" value="${D.tablettes ?? ""}" ${ro}></label></div>
    <div class="row3"><label>Emplacement<select data-mcd="emplacement" ${ro}><option value="">–</option>${MC.d.emplacements.map(x => `<option ${D.emplacement === x ? "selected" : ""}>${esc(x)}</option>`).join("")}</select></label>
      <label>Hauteur<select data-mcd="hauteur" ${ro}><option value="">–</option>${MC.d.hauteurs.map(x => `<option ${D.hauteur === x ? "selected" : ""}>${esc(x)}</option>`).join("")}</select></label>
      <label>Réserve<select data-mcd="reserve" ${ro}><option value="">–</option>${Object.entries(MC.d.reserve).map(([k, l]) => `<option value="${k}" ${D.reserve === k ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></label></div>
    <div class="row3"><div>${yes("promo_en_place", "Promotion en place")}<input data-mcd="promo_txt" value="${esc(D.promo_txt || "")}" placeholder="Laquelle" ${ro}></div><div>${yes("plv_presente", "PLV présente à l'arrivée")}${yes("plv_posee", "PLV posée")}</div><span></span></div>
    <div class="muted" style="margin:10px 0 4px"><b>Travaux réalisés</b></div><div class="chips multi">${MC.d.taches.map(t => `<button type="button" class="chip" data-mctt="${esc(t)}" aria-pressed="${(D.taches || []).includes(t)}" ${ro}>${esc(t)}</button>`).join("")}</div>
    <div class="row"><label>Concurrence<textarea data-mcd="concurrence" rows="2" ${ro}>${esc(D.concurrence || "")}</textarea></label><label>Commentaire<textarea data-mcd="commentaire" rows="2" ${ro}>${esc(D.commentaire || "")}</textarea></label></div>
    <div class="row"><label>Personne rencontrée<input data-mcd="interlocuteur" value="${esc(D.interlocuteur || "")}" ${ro}></label><label>Fonction<input data-mcd="interlocuteur_fonction" value="${esc(D.interlocuteur_fonction || "")}" ${ro}></label></div>
    ${V.photos.some(p => p.slot === "conc") ? `<div class="muted" style="margin:8px 0 4px"><b>Photos de la concurrence</b></div><div class="ph-grid">${ph("conc")}</div>` : ""}
    ${ok ? `<div class="btns"><button class="ghost" id="mcd-save">Enregistrer</button>${V.valide_le ? `<button class="ghost" id="mcd-unval">Retirer la validation</button>` : `<button class="primary" id="mcd-val">Enregistrer et valider</button>`}</div>` : ""}</div>`;
}
function bMc(){
  const re = () => go("rapports");
  $("#mc-go").onclick = () => { MC.f = {from:$("#mc-from").value, to:$("#mc-to").value, statut:$("#mc-st").value, marque:$("#mc-mq").value}; re(); };
  document.querySelectorAll("[data-mcid]").forEach(tr => tr.onclick = e => { if (e.target.closest(".val-ck")) return; MC.sel = +tr.dataset.mcid; re().then(() => scrollTo(0, 0)); });
  document.querySelectorAll("[data-mcck]").forEach(c => c.onchange = () => { c.checked ? MC.vsel.add(+c.dataset.mcck) : MC.vsel.delete(+c.dataset.mcck); });
  if ($("#mc-all")) $("#mc-all").onclick = () => { MC.L.filter(v => !v.valide_le).forEach(v => MC.vsel.add(+v.id)); document.querySelectorAll("[data-mcck]").forEach(c => c.checked = true); };
  if ($("#mc-val")) $("#mc-val").onclick = async () => { if (!MC.vsel.size) return toast("Cochez des visites."); try { const r = await api("mc_valider", {ids:[...MC.vsel]}); toast(r.n + " visite(s) validée(s)"); MC.vsel = new Set(); re(); } catch(e) { toast(e.message); } };
  if (!MC.d) return;
  $("#mcd-x").onclick = () => { MC.sel = null; re(); };
  document.querySelectorAll("[data-mctt]").forEach(b => b.onclick = () => b.setAttribute("aria-pressed", b.getAttribute("aria-pressed") === "true" ? "false" : "true"));
  const save = async valide => { const D = {}, refs = {};
    document.querySelectorAll("[data-mcd]").forEach(i => D[i.dataset.mcd] = i.type === "checkbox" ? (i.checked ? 1 : 0) : i.value);
    D.taches = [...document.querySelectorAll("[data-mctt][aria-pressed=true]")].map(b => b.dataset.mctt);
    document.querySelectorAll("[data-mcrs]").forEach(s => refs[s.dataset.mcrs] = {id:+s.dataset.mcrs, statut:s.value});
    document.querySelectorAll("[data-mcrv]").forEach(i => refs[i.dataset.mcrv][i.dataset.k] = i.value);
    document.querySelectorAll("[data-mcrc]").forEach(i => refs[i.dataset.mcrc][i.dataset.k] = i.checked ? 1 : 0);
    D.refs = Object.values(refs); const pc = {}; document.querySelectorAll("[data-mcph]").forEach(c => pc[c.dataset.mcph] = c.checked ? 1 : 0);
    try { await api("mc_save", Object.assign({id:MC.d.v.id, data:D, photos_client:pc}, valide === undefined ? {} : {valide})); toast(valide ? "Visite validée" : valide === 0 ? "Validation retirée" : "Enregistré"); re(); } catch(e) { toast(e.message); } };
  if ($("#mcd-save")) $("#mcd-save").onclick = () => save();
  if ($("#mcd-val")) $("#mcd-val").onclick = () => save(1);
  if ($("#mcd-unval")) $("#mcd-unval").onclick = () => save(0);
}

/* ---------- Rapports merchandising ---------- */
const MR = { from:"", to:"", zone:"", marques:[], R:null, reco:null, to_:"", msg:"", xlsx:true };
async function loadMcRap(){
  if (!MR.from) { const d = new Date(); MR.from = new Date(d.getFullYear(), d.getMonth() - 1, 1).toISOString().slice(0, 10); MR.to = d.toISOString().slice(0, 10); }
  MR.R = await api("mc_rapport", null, MR.q = new URLSearchParams({from:MR.from, to:MR.to, zone:MR.zone, marques:MR.marques.join(",")}).toString());
}
function vMcRap(){
  const R = MR.R, T = R.stats, pc = v => v == null ? "–" : String(v).replace(".", ",") + " %", eur = v => v == null ? "–" : (+v).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €";
  const reco = MR.reco !== null ? MR.reco : R.reco_auto, qs = () => MR.q + "&reco=" + encodeURIComponent($("#mr-reco") ? $("#mr-reco").value : reco);
  const k = (v, l) => `<div class="kpi"><b>${v}</b><span>${l}</span></div>`;
  return `<div class="card accent"><h2>Rapports merchandising</h2><div class="muted">Le rapport envoyé à la marque à partir des visites validées : distribution, ruptures, prix relevés face au PVP conseillé, linéaire, promotions, PLV, travaux réalisés et photos avant/après.</div>
      <div class="muted" style="margin:8px 0 6px">Marque(s)</div><div class="chips multi" id="mr-mq">${A.L.marques.filter(m => +m.actif).map(m => `<button type="button" class="chip" data-v="${m.id}" aria-pressed="${MR.marques.includes(+m.id)}">${esc(m.nom)}</button>`).join("")}</div>
      <div class="row3"><label>Du<input type="date" id="mr-from" value="${MR.from}"></label><label>Au<input type="date" id="mr-to" value="${MR.to}"></label><label>Secteur<select id="mr-zone">${secteurOpts(MR.zone)}</select></label></div></div>
    ${R.visites ? `<div class="card"><h2>${R.visites} visite${R.visites > 1 ? "s" : ""} validée${R.visites > 1 ? "s" : ""} · ${T.pdv} point${T.pdv > 1 ? "s" : ""} d'intervention</h2>
      <div class="kpis3">${k(pc(T.distribution), "distribution")}${k(pc(T.taux_rupture), "taux de rupture")}${k(pc(T.conformite_prix), "prix conformes (±2 %)")}${k(T.ecart_moyen == null ? "–" : (T.ecart_moyen > 0 ? "+" : "") + String(T.ecart_moyen).replace(".", ",") + " %", "écart au prix conseillé")}
        ${k(pc(T.part_lineaire), "part de linéaire")}${k(T.visites ? Math.round(100 * T.promo / T.visites) + " %" : "–", "promotion en place")}${k(pc(T.etiquetage), "étiquetage présent")}${k(T.facings, "facings relevés")}</div></div>
    <div class="card tablebox"><h2>Par référence</h2><div class="tablewrap"><table class="mc-tab"><tr><th>Référence</th><th class="num">Relevés</th><th class="num">Distribution</th><th class="num">Rupture</th><th class="num">Conseillé</th><th class="num">Prix moyen</th><th class="num">Écart</th><th class="num">Facings</th></tr>
      ${R.refs.map(q => `<tr><td><b>${esc(q.nom)}</b><div class="hint">${esc([q.gamme, q.ean].filter(Boolean).join(" · "))}</div></td><td class="num">${q.n}</td><td class="num">${pc(q.dn)}</td><td class="num ${(q.tx_rupture || 0) >= 10 ? "red" : ""}">${pc(q.tx_rupture)}</td><td class="num">${eur(q.conseille)}</td><td class="num">${eur(q.prix_moy)}</td><td class="num ${q.ecart != null && Math.abs(q.ecart) > 2 ? "red" : ""}">${q.ecart == null ? "–" : (q.ecart > 0 ? "+" : "") + String(q.ecart).replace(".", ",") + " %"}</td><td class="num">${q.facings_moy == null ? "–" : String(q.facings_moy).replace(".", ",")}</td></tr>`).join("")}</table></div></div>
    <div class="card tablebox"><h2>Par point d'intervention</h2><div class="tablewrap"><table class="mc-tab"><tr><th>Point d'intervention</th><th class="num">Visites</th><th>Dernière</th><th class="num">Présentes</th><th class="num">Ruptures</th><th class="num">Non réf.</th><th class="num">Linéaire</th><th>Promo</th><th>PLV</th></tr>
      ${R.pdv.map(q => `<tr><td><b>${esc(q.nom)}</b><div class="hint">${esc(q.ville || "")}</div></td><td class="num">${q.visites}</td><td>${fmtD(q.derniere)}</td><td class="num">${q.present}</td><td class="num ${q.rupture ? "red" : ""}">${q.rupture}</td><td class="num ${q.absent ? "red" : ""}">${q.absent}</td><td class="num">${pc(q.lin)}</td><td>${q.promo ? "oui" : "non"}</td><td>${q.plv ? "oui" : "non"}</td></tr>`).join("")}</table></div></div>
    <div class="card"><label>Recommandations à la marque <span class="hint">· proposées d'après les relevés : modifiez, complétez ou effacez (un tiret seul pour ne rien mettre)</span><textarea id="mr-reco" rows="6">${esc(reco)}</textarea></label>
      <div class="btns"><a class="btn" id="mr-pdf" target="_blank" rel="noopener" href="api.php?action=mc_rapport_pdf&${qs()}">Voir le rapport (PDF)</a><a class="btn" href="api.php?action=mc_xlsx&${MR.q}">Relevés (Excel)</a><button class="ghost" id="mr-re">Proposer à nouveau</button></div>
      ${can("envoi") ? `<div class="muted" style="margin:14px 0 4px"><b>Envoyer à la marque</b></div><label>Destinataires <span class="hint">· séparez par des virgules</span><input id="mr-dest" value="${esc(MR.to_)}" placeholder="contact@marque.fr"></label>
        <label>Message <span class="hint">· facultatif</span><textarea id="mr-msg" rows="3">${esc(MR.msg)}</textarea></label><label class="ck"><input type="checkbox" id="mr-x" ${MR.xlsx ? "checked" : ""}><span>Joindre le fichier Excel des relevés</span></label>
        <div class="btns"><button class="primary" id="mr-send">Envoyer par e-mail</button></div>` : ""}</div>`
    : `<div class="card"><div class="empty">Aucune visite validée sur cette sélection. Validez les visites dans Comptes rendus › Merchandising.</div></div>`}`;
}
function bMcRap(){
  const re = () => go("merch"), keep = () => { if ($("#mr-reco")) MR.reco = $("#mr-reco").value; if ($("#mr-dest")) { MR.to_ = $("#mr-dest").value; MR.msg = $("#mr-msg").value; MR.xlsx = $("#mr-x").checked; } };
  $("#mr-mq").onclick = e => { const b = e.target.closest(".chip"); if (!b) return; const v = +b.dataset.v; MR.marques = MR.marques.includes(v) ? MR.marques.filter(x => x !== v) : MR.marques.concat(v); MR.reco = null; re(); };
  ["#mr-from", "#mr-to", "#mr-zone"].forEach(k => $(k).onchange = () => { MR.from = $("#mr-from").value; MR.to = $("#mr-to").value; MR.zone = $("#mr-zone").value; MR.reco = null; re(); });
  if ($("#mr-re")) $("#mr-re").onclick = () => { MR.reco = null; re(); };
  if ($("#mr-pdf")) $("#mr-pdf").onclick = () => { keep(); $("#mr-pdf").href = "api.php?action=mc_rapport_pdf&" + MR.q + "&reco=" + encodeURIComponent(MR.reco); };
  if ($("#mr-send")) $("#mr-send").onclick = async () => { keep(); const b = $("#mr-send"); b.disabled = true;
    try { const r = await api("mc_send", {from:MR.from, to:MR.to, zone:MR.zone, marques:MR.marques, reco:MR.reco, message:MR.msg, xlsx:MR.xlsx ? 1 : 0, dest:MR.to_}); toast(r.message); try { const L = await api("admin_lists"); A.L.envois = L.envois; } catch(_) {} } catch(e) { toast(e.message); } b.disabled = false; };
}

/* ---------- Catalogue merchandising de la marque et liste à relever (page Marques) ---------- */
const RF = { mq:0, L:[], ops:[], op:0, sel:null, colle:false, imp:null, q:"", g:"", only:false, lim:150 };
/** Gencod valide (EAN-13, EAN-8 ou UPC-A) : clé de contrôle vérifiée. */
const eanOk = c => { c = String(c || "").replace(/\D/g, ""); if (c.length === 12) c = "0" + c; if (c.length !== 13 && c.length !== 8) return false;
  const d = c.split("").map(Number), k = d.pop(); const s = d.reduce((a, x, i) => a + x * ((c.length === 13 ? i % 2 : (i + 1) % 2) ? 3 : 1), 0); return (10 - s % 10) % 10 === k; };
async function loadMcRefs(){ if (!RF.mq && A.L.marques.length) RF.mq = +A.L.marques[0].id; const r = RF.mq ? await api("mc_refs", null, "marque=" + RF.mq) : {refs:[], ops:[]};
  RF.L = r.refs.filter(x => +x.actif).map(x => Object.assign(x, {a_relever:+x.a_relever})); RF.ops = r.ops || []; if (!RF.ops.find(o => o.id === RF.op)) RF.op = 0; rfSel(); }
/** Liste propre à l'opération choisie (sinon la liste par défaut). */
function rfSel(){ const o = RF.ops.find(x => x.id === RF.op); RF.sel = o ? new Set(o.sel.length ? RF.L.filter(r => o.sel.includes(+r.id)) : RF.L.filter(r => r.a_relever)) : null; }
const rfOn = r => RF.sel ? RF.sel.has(r) : !!r.a_relever;
const rfVus = () => { const q = RF.q.trim().toLowerCase(); return RF.L.map((r, i) => [r, i]).filter(([r]) => (!RF.g || r.gamme === RF.g) && (!RF.only || rfOn(r)) && (!q || [r.nom, r.gamme, r.ean, r.code].join(" ").toLowerCase().includes(q))); };
const RF_CH = [["nom", "Désignation (obligatoire)"], ["ean", "Gencod (EAN)"], ["gamme", "Gamme"], ["code", "Code article"], ["prix", "PVP conseillé"]];
function vMcRefs(){
  const V = rfVus(), n = RF.L.filter(rfOn).length, gam = [...new Set(RF.L.map(r => r.gamme).filter(Boolean))].sort(), op = RF.ops.find(o => o.id === RF.op);
  const row = ([r, i]) => `<tr data-rfi="${i}"><td style="text-align:center"><input type="checkbox" data-rfk="${i}" ${rfOn(r) ? "checked" : ""} aria-label="À relever"></td>
    <td><input data-rf="nom" value="${esc(r.nom || "")}" placeholder="Ex. Crème hydratante 50 ml"></td><td><input data-rf="gamme" value="${esc(r.gamme || "")}" placeholder="Soin visage" style="width:120px"></td><td><input data-rf="code" value="${esc(r.code || "")}" style="width:90px"></td>
    <td><input data-rf="ean" value="${esc(r.ean || "")}" inputmode="numeric" placeholder="13 chiffres" style="width:140px;${r.ean && !eanOk(r.ean) ? "border-color:#C0392B;color:#C0392B" : ""}">${r.ean && !eanOk(r.ean) ? `<div class="hint" style="color:#C0392B">gencod invalide</div>` : ""}</td>
    <td><input data-rf="prix" value="${r.prix_conseille == null || r.prix_conseille === "" ? "" : String(r.prix_conseille).replace(".", ",")}" inputmode="decimal" style="width:80px">${r.pvp_par ? `<div class="hint" title="${esc(r.pvp_par)}">${/\(marque\)$/.test(r.pvp_par) ? "par la marque" : "par l'agence"}${r.pvp_le ? " le " + esc(fmtD(String(r.pvp_le).slice(0, 10))) : ""}</div>` : ""}</td><td><button type="button" class="ghost sm" data-rfx="${i}">✕</button></td></tr>`;
  const I = RF.imp, imp = I ? `<div class="card" style="background:var(--sand);margin:12px 0"><h3 style="margin-top:0">Fichier « ${esc(I.fichier)} » : ${I.lignes.length} ligne${I.lignes.length > 1 ? "s" : ""}</h3><div class="muted">Vérifiez la colonne utilisée pour chaque information (repérée d'après les titres du fichier). Les références déjà au catalogue sont reconnues par leur gencod, puis leur code article, puis leur désignation : leurs informations sont mises à jour.</div>
      <div class="row3">${RF_CH.map(([k, l]) => `<label>${l}<select data-rfmap="${k}"><option value="">(aucune)</option>${I.colonnes.map((c, j) => `<option value="${j}" ${I.map[k] === j ? "selected" : ""}>${esc(c)}</option>`).join("")}</select></label>`).join("")}
        <label class="ck" style="align-self:end"><input type="checkbox" id="rf-imp-rel" ${I.rel ? "checked" : ""}><span>Cocher « à relever » les nouvelles références</span></label></div>
      <div class="tablewrap"><table><tr>${RF_CH.map(([, l]) => `<th>${esc(l.replace(" (obligatoire)", ""))}</th>`).join("")}</tr>${I.lignes.slice(0, 5).map(L => `<tr>${RF_CH.map(([k]) => `<td>${esc(I.map[k] == null || I.map[k] === "" ? "" : L[I.map[k]] || "")}</td>`).join("")}</tr>`).join("")}</table></div>
      <div class="btns"><button type="button" class="primary" id="rf-imp-ok">Importer dans le catalogue</button><button type="button" class="ghost" id="rf-imp-x">Annuler</button></div></div>` : "";
  return `<div class="card"><h2>Catalogue merchandising et références à relever</h2><div class="muted">Importez le fichier de la marque (Excel ou CSV : désignation, gencod, gamme, code article, PVP), puis cochez les références que les merchandiseurs doivent relever. Le PVP conseillé sert à mesurer l'écart de prix dans les rapports ; la marque peut aussi le saisir depuis son espace. Une référence retirée garde son historique.</div>
    <div class="row3"><label>Marque<select id="rf-mq">${A.L.marques.map(m => `<option value="${m.id}" ${+m.id === RF.mq ? "selected" : ""}>${esc(m.nom)}</option>`).join("")}</select></label>
      <label>Liste à relever pour<select id="rf-op"><option value="0">Toutes les visites (liste par défaut)</option>${RF.ops.map(o => `<option value="${o.id}" ${o.id === RF.op ? "selected" : ""}>${esc(o.nom)}${o.sel.length ? " · liste propre" : ""}</option>`).join("")}</select></label>
      <label>Importer le fichier de la marque<input type="file" id="rf-file" accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"></label></div>
    ${op ? `<div class="hint" style="margin:-4px 0 8px">${op.sel.length ? `Cette opération a sa propre liste.` : `Cette opération suit pour l'instant la liste par défaut : cochez ou décochez puis enregistrez pour lui donner sa propre liste.`}${op.sel.length ? ` <button type="button" class="linkbtn" id="rf-op-def">Revenir à la liste par défaut</button>` : ""}</div>` : ""}
    ${imp}
    <div class="row3"><label>Rechercher<input id="rf-q" value="${esc(RF.q)}" placeholder="Désignation, gencod, code…"></label><label>Gamme<select id="rf-g"><option value="">Toutes</option>${gam.map(g => `<option ${g === RF.g ? "selected" : ""}>${esc(g)}</option>`).join("")}</select></label>
      <div style="align-self:end" class="hint"><b>${RF.L.length}</b> référence${RF.L.length > 1 ? "s" : ""} au catalogue · <b>${n}</b> à relever${op ? " pour cette opération" : ""}<br><label class="ck" style="display:inline-flex;margin:4px 0"><input type="checkbox" id="rf-only" ${RF.only ? "checked" : ""}><span>n'afficher que les références à relever</span></label><br><button type="button" class="linkbtn" data-rfall="1">cocher</button> · <button type="button" class="linkbtn" data-rfall="0">décocher</button> les ${V.length} affichée${V.length > 1 ? "s" : ""}</div></div>
    <div class="tablewrap"><table><tr><th>À relever</th><th>Désignation</th><th>Gamme</th><th>Code</th><th>Gencod (EAN)</th><th>PVP (€)</th><th></th></tr>${V.slice(0, RF.lim).map(row).join("")}</table></div>
    ${V.length > RF.lim ? `<div class="btns"><button type="button" class="ghost" id="rf-plus">Afficher ${Math.min(300, V.length - RF.lim)} de plus (${V.length - RF.lim} restantes)</button></div>` : ""}
    ${RF.colle ? `<label style="margin-top:10px">Collez ici les colonnes copiées depuis Excel <span class="hint">· dans l'ordre : Désignation, Gamme, Gencod, PVP (une ligne par référence, la ligne de titres est ignorée)</span><textarea id="rf-txt" rows="6" placeholder="Crème hydratante 50 ml	Soin visage	2099000010017	18,90"></textarea></label>
      <div class="btns"><button type="button" class="primary" id="rf-colle-ok">Ajouter ces références</button><button type="button" class="ghost" id="rf-colle-x">Annuler</button></div>` : ""}
    <div class="btns"><button type="button" class="ghost" id="rf-add">+ Ajouter une référence</button><button type="button" class="ghost" id="rf-colle">Coller depuis Excel</button><button type="button" class="primary" id="rf-save">Enregistrer</button></div>
    <div class="btns" style="margin-top:0"><a class="btn ghost" href="api.php?action=mc_refs_exemple">Fichier d'exemple (Excel)</a><a class="btn ghost" target="_blank" rel="noopener" href="api.php?action=mc_planche&marque=${RF.mq}">Planche de gencods à imprimer (PDF)</a></div>
    <div class="hint">Dans l'appli, chaque visite affiche la liste à relever de son opération. Un produit du catalogue scanné hors liste s'ajoute au relevé ; un gencod inconnu est noté à part.</div></div>`;
}
function bMcRefs(){
  if (!$("#rf-mq")) return;
  const read = () => { document.querySelectorAll("[data-rfi]").forEach(tr => { const r = RF.L[+tr.dataset.rfi]; tr.querySelectorAll("[data-rf]").forEach(i => r[i.dataset.rf === "prix" ? "prix_conseille" : i.dataset.rf] = i.value); }); };
  const redrawRF = (keep) => { const y = scrollY, f = document.activeElement && document.activeElement.id; $("#rf-mq").closest(".card").outerHTML = vMcRefs(); bMcRefs(); scrollTo(0, y); if (keep && f && $("#" + f)) { const e = $("#" + f); e.focus(); if (e.setSelectionRange) e.setSelectionRange(e.value.length, e.value.length); } };
  const re = () => { const y = scrollY; go("marques").then(() => scrollTo(0, y)); };
  $("#rf-mq").onchange = e => { RF.mq = +e.target.value; RF.op = 0; RF.q = ""; RF.g = ""; RF.imp = null; re(); };
  $("#rf-op").onchange = e => { read(); RF.op = +e.target.value; rfSel(); redrawRF(); };
  if ($("#rf-op-def")) $("#rf-op-def").onclick = async () => { try { await api("mc_sel_save", {devis:RF.op, ids:[]}); toast("L'opération suit de nouveau la liste par défaut"); re(); } catch(e) { toast(e.message); } };
  $("#rf-q").oninput = e => { read(); RF.q = e.target.value; RF.lim = 150; redrawRF(true); };
  $("#rf-g").onchange = e => { read(); RF.g = e.target.value; RF.lim = 150; redrawRF(); };
  $("#rf-only").onchange = e => { read(); RF.only = e.target.checked; RF.lim = 150; redrawRF(); };
  if ($("#rf-plus")) $("#rf-plus").onclick = () => { read(); RF.lim += 300; redrawRF(); };
  document.querySelectorAll("[data-rfk]").forEach(c => c.onchange = () => { const r = RF.L[+c.dataset.rfk]; if (RF.sel) { c.checked ? RF.sel.add(r) : RF.sel.delete(r); } else r.a_relever = c.checked ? 1 : 0; const y = scrollY; read(); redrawRF(); scrollTo(0, y); });
  document.querySelectorAll("[data-rfall]").forEach(b => b.onclick = () => { read(); const on = b.dataset.rfall === "1"; rfVus().forEach(([r]) => { if (RF.sel) { on ? RF.sel.add(r) : RF.sel.delete(r); } else r.a_relever = on ? 1 : 0; }); redrawRF(); });
  $("#rf-add").onclick = () => { read(); const r = {nom:"", gamme:"", code:"", ean:"", prix_conseille:"", a_relever:1}; RF.L.unshift(r); if (RF.sel) RF.sel.add(r); RF.q = ""; RF.g = ""; redrawRF(); };
  document.querySelectorAll("[data-rfx]").forEach(b => b.onclick = () => { read(); const r = RF.L[+b.dataset.rfx]; if (RF.sel) RF.sel.delete(r); RF.L.splice(+b.dataset.rfx, 1); redrawRF(); });
  $("#rf-colle").onclick = () => { read(); RF.colle = true; redrawRF(); setTimeout(() => $("#rf-txt") && $("#rf-txt").focus(), 50); };
  if ($("#rf-colle-x")) $("#rf-colle-x").onclick = () => { RF.colle = false; redrawRF(); };
  if ($("#rf-colle-ok")) $("#rf-colle-ok").onclick = () => { read(); let n = 0;
    $("#rf-txt").value.split(/\r?\n/).map(l => l.split(/\t|;/).map(x => x.trim())).filter(c => c[0] && !/^(r[ée]f[ée]rence|d[ée]signation|libell[ée])$/i.test(c[0])).forEach(c => {
      const ean = (c[2] || "").replace(/\D/g, ""), ex = ean && RF.L.find(x => String(x.ean || "") === ean);
      const r = {nom:c[0], gamme:c[1] || "", ean, prix_conseille:(c[3] || "").replace(/[^\d,.]/g, "")}; if (ex) Object.assign(ex, r); else { r.a_relever = 1; RF.L.push(r); if (RF.sel) RF.sel.add(r); } n++; });
    RF.colle = false; toast(n + " référence" + (n > 1 ? "s" : "") + " ajoutée" + (n > 1 ? "s" : "") + " : vérifiez puis enregistrez"); redrawRF(); };
  // Import du fichier de la marque : lecture côté serveur, correspondance des colonnes vérifiée ici, fusion dans la liste avant l'enregistrement.
  $("#rf-file").onchange = async e => { const f = e.target.files[0]; if (!f) return; read(); const fd = new FormData(); fd.append("fichier", f);
    try { toast("Lecture du fichier…"); const r = await api("mc_refs_import", fd); RF.imp = {fichier:f.name, colonnes:r.colonnes, lignes:r.lignes, map:r.map || {}, rel:RF.L.length === 0}; redrawRF(); } catch(x) { toast(x.message); e.target.value = ""; } };
  document.querySelectorAll("[data-rfmap]").forEach(s => s.onchange = () => { read(); RF.imp.map[s.dataset.rfmap] = s.value === "" ? "" : +s.value; redrawRF(); });
  if ($("#rf-imp-rel")) $("#rf-imp-rel").onchange = e => { RF.imp.rel = e.target.checked; };
  if ($("#rf-imp-x")) $("#rf-imp-x").onclick = () => { read(); RF.imp = null; redrawRF(); };
  if ($("#rf-imp-ok")) $("#rf-imp-ok").onclick = () => { read(); const I = RF.imp, M = I.map, g = (L, k) => M[k] === "" || M[k] == null ? "" : String(L[M[k]] == null ? "" : L[M[k]]).trim();
    if (M.nom === "" || M.nom == null) return toast("Choisissez la colonne de la désignation.");
    const idx = {}; RF.L.forEach(r => { if (r.ean) idx["e" + String(r.ean).replace(/\D/g, "").padStart(13, "0")] = r; if (r.code) idx["c" + String(r.code).toLowerCase()] = r; idx["n" + String(r.nom).toLowerCase()] = idx["n" + String(r.nom).toLowerCase()] || r; });
    let maj = 0, aj = 0, bad = 0;
    I.lignes.forEach(L => { const nom = g(L, "nom"); let ean = g(L, "ean").replace(/\D/g, ""); if (!nom && !ean) return; if (ean.length === 12) ean = "0" + ean; if (ean && !eanOk(ean)) { bad++; }
      const px = g(L, "prix").replace(/[^\d,.]/g, ""), code = g(L, "code"), gm = g(L, "gamme");
      const ex = (ean && idx["e" + ean.padStart(13, "0")]) || (code && idx["c" + code.toLowerCase()]) || (nom && idx["n" + nom.toLowerCase()]);
      if (ex) { if (nom) ex.nom = nom; if (ean) ex.ean = ean; if (gm) ex.gamme = gm; if (code) ex.code = code; if (px) ex.prix_conseille = px; maj++; return; }
      if (!nom) return; const r = {nom, ean, gamme:gm, code, prix_conseille:px, a_relever:I.rel ? 1 : 0}; RF.L.push(r); if (RF.sel && I.rel) RF.sel.add(r); if (ean) idx["e" + ean.padStart(13, "0")] = r; idx["n" + nom.toLowerCase()] = r; aj++; });
    RF.imp = null; toast(`${aj} référence${aj > 1 ? "s" : ""} ajoutée${aj > 1 ? "s" : ""}, ${maj} mise${maj > 1 ? "s" : ""} à jour${bad ? `, ${bad} gencod${bad > 1 ? "s" : ""} invalide${bad > 1 ? "s" : ""} (en rouge)` : ""} : vérifiez puis enregistrez`); redrawRF(); };
  $("#rf-save").onclick = async () => { read(); const b = $("#rf-save"); b.disabled = true;
    try { const r = await api("mc_refs_save", {marque:RF.mq, refs:RF.L.map(x => ({id:x.id || 0, nom:x.nom, gamme:x.gamme, code:x.code || "", ean:x.ean, prix:x.prix_conseille, a_relever:x.a_relever ? 1 : 0}))});
      if (RF.op) { const ids = []; RF.L.forEach((x, i) => { if (RF.sel.has(x) && r.ids[i]) ids.push(+r.ids[i]); }); await api("mc_sel_save", {devis:RF.op, ids}); }
      toast(RF.op ? "Catalogue et liste de l'opération enregistrés" : "Catalogue enregistré"); re(); } catch(e) { toast(e.message); b.disabled = false; } };
}
