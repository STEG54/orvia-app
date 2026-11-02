// Espace marques : consultation des comptes rendus validés par l'agence.
const capi = (action, body, query) => { const url = "marques_api.php?action=" + action + (query ? "&" + query : "");
  return fetch(url, body ? {method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/json","X-Requested-With":"amaya"}, body:JSON.stringify(body)} : {credentials:"same-origin"})
    .then(async r => { const d = await r.json().catch(() => ({})); if (!r.ok) { const e = new Error(d.error || "Erreur " + r.status); e.status = r.status; throw e; } return d; })
    .catch(e => { if (e instanceof TypeError) throw new Error("Pas de connexion internet."); throw e; }); };
const iso = d => new Date(d.getTime() - d.getTimezoneOffset() * 6e4).toISOString().slice(0, 10);
const C = { ph:{source:"", magasin:"", qui:"", sel:new Set(), d:null}, mr:[], mrForm:false, s:null, d:null, vue:"anim", b:null, ba:"", bxl:"annee", f:{from:"", to:"", marques:[], zone:"", magasin:"", animatrice:""}, xfmt:"mois", xmag:false, open:null };
(() => { const n = new Date(); C.f.from = iso(new Date(n.getFullYear(), 0, 1)); C.f.to = iso(n); })(); // par défaut : depuis le 1er janvier
const eur = v => v == null ? "–" : (+v).toLocaleString("fr-FR", {maximumFractionDigits:0}) + " €";
const pct = (ca, ob) => ob > 0 ? ((ca / ob - 1) >= 0 ? "+" : "") + Math.round((ca / ob - 1) * 100) + " %" : "";
const dfr = s => new Date(s + "T12:00:00").toLocaleDateString("fr-FR", {weekday:"short", day:"numeric", month:"short", year:"numeric"});
const can = k => C.s && C.s.compte.droits.includes(k);

async function boot(){
  try { C.s = await capi("session"); } catch(e) { $("#view").innerHTML = `<div class="card"><b>Connexion impossible</b><div class="muted">${esc(e.message)}</div></div>`; return; }
  if (!C.s.compte) return vLogin();
  const ms = C.s.marques; C.f.marques = ms.map(m => m.id);
  C.xfmt = "mois"; C.xmag = ms.some(m => m.ventile); if (C.s.compte.evts) C.vue = "evts";
  $("#who").innerHTML = `${esc(C.s.compte.nom)}<br>${C.s.apercu || (typeof AMAYA_DEMO !== "undefined" && AMAYA_DEMO) ? "" : `<button id="secu" style="margin-top:4px">Sécurité</button> `}<button id="logout" style="margin-top:4px">Se déconnecter</button>`;
  if ($("#secu")) $("#secu").onclick = vSecu;
  $("#logout").onclick = async () => { await capi("logout", {}); if (typeof AMAYA_DEMO !== "undefined" && AMAYA_DEMO) location.href = "demo.php"; else location.reload(); };
  if (!ms.length && C.s.devis) C.vue = "devis";
  if (C.s.apercu) { $("#logout").textContent = "Quitter l'aperçu";
    document.querySelector("main, #view").insertAdjacentHTML("beforebegin", `<div class="apercu-bar">Aperçu administrateur : vous voyez exactement ce que voit <b>${esc(C.s.compte.nom)}</b> (${esc(C.s.compte.email)}).</div>`);
    $("#logout").onclick = async () => { await capi("logout", {}); window.close(); location.href = "admin.php"; }; }
  await loadMr(); load();
}
async function loadMr(){ try { C.mr = (await capi("mes_rapports")).rapports; } catch(e) { C.mr = []; } }
/** Mes rapports : sélections enregistrées (période, marques, point de vente, intervenant(e), format), à rouvrir ou supprimer. */
function vMr(){
  return `<div class="card"><h2>Mes rapports</h2>
    ${C.mr.length ? `<div class="mr-list">${C.mr.map(r => `<div class="mr-item"><button type="button" class="linkbtn" data-mro="${r.id}"><b>${esc(r.nom)}</b></button><span class="hint">${esc(mrTxt(r.params))}</span><button type="button" class="ghost" data-mrd="${r.id}">Supprimer</button></div>`).join("")}</div>`
      : `<div class="muted">Enregistrez la sélection affichée pour la retrouver en un clic.</div>`}
    ${C.mrForm ? `<div class="row"><label>Nom du rapport<input id="mr-nom" placeholder="Ex. Bilan mensuel Île-de-France"></label><label>&nbsp;<button class="primary" id="mr-ok">Enregistrer</button></label></div>`
      : `<div class="btns"><button class="ghost" id="mr-new">Enregistrer cette sélection</button></div>`}</div>`;
}
const mrTxt = p => { const v = "Animations";
  return v + " · " + "du " + new Date((p.from || "") + "T12:00:00").toLocaleDateString("fr-FR") + " au " + new Date((p.to || "") + "T12:00:00").toLocaleDateString("fr-FR") + (p.magasin ? " · " + p.magasin : ""); };
function bindMr(){
  if ($("#mr-new")) $("#mr-new").onclick = () => { C.mrForm = true; render(); setTimeout(() => $("#mr-nom") && $("#mr-nom").focus(), 50); };
  if ($("#mr-ok")) { const save = async () => { const nom = $("#mr-nom").value.trim(); if (!nom) return toast("Donnez un nom au rapport.");
      try { await capi("rapport_save", {nom, params:{vue:C.vue, from:C.f.from, to:C.f.to, marques:C.f.marques, zone:C.f.zone, magasin:C.f.magasin, animatrice:C.f.animatrice, xfmt:C.xfmt, xmag:C.xmag, ba:C.ba, bxl:C.bxl}});
        C.mrForm = false; await loadMr(); toast("Rapport enregistré"); render(); } catch(e) { toast(e.message); } };
    $("#mr-ok").onclick = save; $("#mr-nom").onkeydown = e => { if (e.key === "Enter") save(); }; }
  document.querySelectorAll("[data-mro]").forEach(b => b.onclick = () => { const r = C.mr.find(x => x.id === +b.dataset.mro); if (!r) return; const p = r.params;
    C.vue = p.vue || "anim"; Object.assign(C.f, {from:p.from || C.f.from, to:p.to || C.f.to, zone:p.zone || "", magasin:p.magasin || "", animatrice:p.animatrice || ""});
    const ok = C.s.marques.map(m => m.id); C.f.marques = (p.marques || []).filter(id => ok.includes(id)); if (!C.f.marques.length) C.f.marques = ok;
    if (p.xfmt) C.xfmt = p.xfmt; C.xmag = !!p.xmag; C.ba = p.ba || ""; C.bxl = p.bxl || "annee"; C.baList = null; toast("Rapport « " + r.nom + " » ouvert"); load(); });
  document.querySelectorAll("[data-mrd]").forEach(b => b.onclick = async () => { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer"; return; }
    try { await capi("rapport_delete", {id:+b.dataset.mrd}); await loadMr(); toast("Rapport supprimé"); render(); } catch(e) { toast(e.message); } });
}
function vLogin(){
  if (typeof AMAYA_DEMO !== "undefined" && AMAYA_DEMO) { location.href = "demo.php"; return; }
  $("#who").textContent = "";
  $("#view").innerHTML = `<div class="card login accent"><h1>Espace marques</h1>
    <div class="muted">Retrouvez les comptes rendus d'animation, les chiffres, les photos et vos reportings, validés par ${AGN}.</div>
    <label>Adresse e-mail<input id="em" type="email" autocomplete="username"></label><label>Mot de passe<input id="pw" type="password" autocomplete="current-password"></label>
    <button class="primary" id="go">Se connecter</button><div class="hint">Identifiants transmis par ${AGN}. Mot de passe oublié : écrivez à ${esc(CONTACT)}.</div></div>`;
  const go = async () => { try { const r = await capi("login", {email:$("#em").value, password:$("#pw").value}); if (r.totp) return vTotp(); boot(); } catch(e) { toast(e.message); } };
  $("#go").onclick = go; $("#pw").onkeydown = e => { if (e.key === "Enter") go(); };
}
/** 2e étape de connexion pour les comptes qui ont activé la double authentification. */
function vTotp(){
  $("#view").innerHTML = `<div class="card login accent"><h1>Code de vérification</h1>
    <div class="muted">Saisissez le code à 6 chiffres affiché par votre appli d'authentification, ou un code de secours.</div>
    <label>Code<input id="tfc" inputmode="numeric" maxlength="9" class="pin" placeholder="123456" autocomplete="one-time-code"></label>
    <button class="primary" id="tfgo">Valider</button><button class="ghost" id="tfx">Annuler</button></div>`;
  const g = async () => { try { await capi("login_totp", {code:$("#tfc").value}); boot(); } catch(e) { toast(e.message); if (e.status === 429 || /expirée|recommencez/i.test(e.message)) vLogin(); } };
  $("#tfgo").onclick = g; $("#tfc").onkeydown = e => { if (e.key === "Enter") g(); }; $("#tfx").onclick = vLogin; setTimeout(() => $("#tfc").focus(), 50);
}
/** Sécurité du compte : double authentification facultative (recommandée). */
async function vSecu(){
  let E; try { E = await capi("tf_etat"); } catch(e) { return toast(e.message); }
  const m = document.createElement("div"); m.className = "modal";
  const box = h => { m.innerHTML = `<div class="modal-box"><button class="ghost modal-x" data-x>✕</button><h2>Sécurité du compte</h2>${h}</div>`; };
  const etat = () => { box(E.actif ? `<div class="muted"><span class="pill ok">Double authentification activée</span> ${E.secours} code${E.secours > 1 ? "s" : ""} de secours restant${E.secours > 1 ? "s" : ""}.</div>
      <label>Pour la désactiver, votre mot de passe<input id="sd-pw" type="password" autocomplete="current-password"></label><div class="btns"><button class="ghost" id="sd-off">Désactiver</button></div>`
    : `<div class="muted">Recommandé : en plus du mot de passe, un code à 6 chiffres de votre téléphone (Google Authenticator, Microsoft Authenticator…) sera demandé à chaque connexion.</div><div class="btns"><button class="primary" id="sd-on">Activer la double authentification</button></div>`);
    if ($("#sd-on")) $("#sd-on").onclick = prep;
    if ($("#sd-off")) $("#sd-off").onclick = async () => { try { await capi("tf_desactiver", {password:$("#sd-pw").value}); E = await capi("tf_etat"); toast("Double authentification désactivée"); etat(); } catch(e) { toast(e.message); } }; };
  const prep = async () => { let P; try { P = await capi("tf_preparer", {}); } catch(e) { return toast(e.message); }
    box(`<div>1. Installez une appli d'authentification sur votre téléphone.<br>2. Scannez ce QR code avec l'appli.</div><div id="sd-qr" style="align-self:center;background:#fff;padding:10px;border-radius:12px;margin:8px auto;width:max-content"></div>
      <div class="hint">Sans appareil photo : saisissez la clé <b>${esc(P.secret)}</b></div><label>3. Code affiché par l'appli<input id="sd-c" inputmode="numeric" maxlength="6" class="pin" placeholder="123456"></label><div class="btns"><button class="primary" id="sd-ok">Activer</button></div>`);
    try { const q = qrcode(0, "M"); q.addData(P.uri); q.make(); $("#sd-qr").innerHTML = q.createSvgTag({cellSize:5, margin:0}); } catch(x) { $("#sd-qr").remove(); }
    $("#sd-ok").onclick = async () => { try { const r = await capi("tf_activer", {code:$("#sd-c").value}); E = await capi("tf_etat");
      box(`<div class="muted"><span class="pill ok">Activée</span> Gardez ces codes de secours en lieu sûr : chacun permet une connexion si vous n'avez plus votre téléphone.</div><pre style="font-size:15px;line-height:1.7">${r.secours.map(esc).join("\n")}</pre><div class="btns"><button class="primary" data-x>Terminé</button></div>`); } catch(e) { toast(e.message); } }; };
  etat(); document.body.appendChild(m);
  m.onclick = e => { if (e.target === m || e.target.closest("[data-x]")) m.remove(); };
}
const q = () => new URLSearchParams({from:C.f.from, to:C.f.to, marques:C.f.marques.join(","), zone:C.f.zone, magasin:C.f.magasin, animatrice:C.f.animatrice}).toString();
async function load(){
  try { if (C.vue === "evts") return loadEvtsC(); if (C.vue === "mc") return loadMcC(); if (C.vue === "devis") return loadDevis(); if (C.vue === "photos") return loadPh(); C.d = await capi("data", null, q()); render(); } catch(e) { if (e.status === 401) return vLogin(); toast(e.message); }
}
function preset(k){ const n = new Date(), y = n.getFullYear(), m = n.getMonth(), dow = (n.getDay() + 6) % 7, mon = new Date(y, m, n.getDate() - dow);
  return ({sem1:[new Date(mon.getFullYear(), mon.getMonth(), mon.getDate() - 7), new Date(mon.getFullYear(), mon.getMonth(), mon.getDate() - 1)], mois:[new Date(y, m, 1), n],
    mois1:[new Date(y, m - 1, 1), new Date(y, m, 0)], an:[new Date(y, 0, 1), n]})[k]; }
/** Onglets selon le compte : animations, événements, photothèque, devis. */
function vues(){
  const s = C.s, t = [...(s.compte.evts ? [["evts", "Événements et hôtessariat"]] : []), ...(s.marques.length ? [["anim", "Animations commerciales"]] : []), ...(s.compte.merch ? [["mc", "Merchandising"]] : []), ...(can("photos") ? [["photos", "Photothèque"]] : []), ...(s.devis ? [["devis", "Devis (" + s.devis + ")"]] : [])];
  return t.length > 1 ? `<div class="chips" id="c-vue" style="margin-bottom:12px">${t.map(([k, l]) => `<button type="button" class="chip" data-v="${k}" aria-pressed="${C.vue === k}">${esc(l)}</button>`).join("")}</div>` : "";
}
function bindVues(){ if ($("#c-vue")) $("#c-vue").onclick = e => { const b = e.target.closest(".chip"); if (!b || b.dataset.v === C.vue) return; C.vue = b.dataset.v; C.ba = ""; load(); }; }
const pres = () => `<div class="presets">${[["sem1","Semaine dernière"],["mois","Ce mois-ci"],["mois1","Mois dernier"],["an","Depuis le 1er janvier"]].map(([v, l]) => `<button type="button" class="chip" data-pre="${v}">${l}</button>`).join("")}</div>`;
/* ---------- Devis : validation en ligne ---------- */
async function loadDevis(){ C.dv = (await capi("devis")).devis; renderDevis(); }
function renderDevis(){
  const L = C.dv || [], st = {envoye:["En attente de votre accord", ""], accepte:["Accepté", "ok"], refuse:["Refusé", "bad"]};
  const e2 = v => (+v).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €";
  $("#view").innerHTML = vues() + `<div class="card accent"><h2>Vos devis</h2><div class="muted">Consultez chaque devis et donnez votre accord en ligne : il vaut bon pour accord, sans papier à renvoyer.</div></div>
    ${L.map(d => `<div class="card dv"><div class="dv-h"><div><b>Devis ${esc(d.numero)}</b> <span class="pill ${(st[d.statut] || [])[1] || ""}">${esc(d.expire ? "Expiré" : (st[d.statut] || [d.statut])[0])}</span>
        <div class="muted">${esc(d.objet)}${d.ref ? " · " + esc(d.ref) : ""}</div><div class="hint">Du ${esc(dfr(d.date))}${d.validite ? ", valable jusqu'au " + esc(dfr(d.validite)) : ""}</div></div>
      <div class="dv-m"><b>${e2(d.ht)} HT</b><span class="muted">${e2(d.ttc)} TTC</span></div></div>
      <div class="btns"><a class="btn" target="_blank" rel="noopener" href="marques_api.php?action=devis_pdf&id=${d.id}">Voir le devis (PDF)</a></div>
      ${d.statut === "accepte" ? `<div class="dv-ok">Accepté${d.accepte_le ? ` le ${esc(d.accepte_le.slice(8, 10) + "/" + d.accepte_le.slice(5, 7) + "/" + d.accepte_le.slice(0, 4) + " à " + d.accepte_le.slice(11, 16))} par ${esc(d.accepte_par)}` : ""}.</div>`
        : d.statut === "refuse" ? `<div class="muted">Refusé${d.refus ? " : " + esc(d.refus) : ""}.</div>`
        : d.expire ? `<div class="muted">Ce devis a expiré : contactez ${AGN} pour le renouveler.</div>`
        : `<div class="dv-f" data-dv="${d.id}"><div class="row3"><label>Votre nom<input data-k="nom" value="${esc(C.s.compte.nom)}"></label><label>Votre fonction<input data-k="fonction" placeholder="Ex. Responsable trade marketing"></label><span></span></div>
          <label class="ck"><input type="checkbox" data-k="cgv"><span>Bon pour accord : j'accepte le devis ${esc(d.numero)} d'un montant de ${e2(d.ht)} HT et les conditions générales de vente.</span></label>
          <div class="btns"><button class="primary" data-dvok="${d.id}">Valider le devis</button><button class="ghost" data-dvno="${d.id}">Refuser</button></div>
          <label hidden data-motif>Motif du refus <span class="hint">· facultatif</span><textarea data-k="motif" rows="2"></textarea></label></div>`}</div>`).join("") || `<div class="card"><div class="empty">Aucun devis pour l'instant.</div></div>`}`;
  bindVues();
  const send = async (id, decision) => { const f = document.querySelector(`[data-dv="${id}"]`), v = {}; f.querySelectorAll("[data-k]").forEach(i => v[i.dataset.k] = i.type === "checkbox" ? i.checked : i.value.trim());
    if (decision === "accepte" && !v.cgv) return toast("Cochez la case « Bon pour accord ».");
    try { await capi("devis_repondre", {id, decision, nom:v.nom, fonction:v.fonction, cgv:v.cgv ? 1 : 0, motif:v.motif}); toast(decision === "accepte" ? "Merci, devis validé." : "Réponse enregistrée."); loadDevis(); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-dvok]").forEach(b => b.onclick = () => { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer la validation"; return; } send(+b.dataset.dvok, "accepte"); });
  document.querySelectorAll("[data-dvno]").forEach(b => b.onclick = () => { const f = b.closest(".dv-f"), m = f.querySelector("[data-motif]");
    if (m.hidden) { m.hidden = false; b.textContent = "Confirmer le refus"; return; } send(+b.dataset.dvno, "refuse"); });
}

/* ---------- Rapports d'événement ---------- */
async function loadEvtsC(){ C.ev = (await capi("evts", null, new URLSearchParams({from:C.f.from, to:C.f.to}).toString())).evts; renderEvtsC(); }
function renderEvtsC(){
  const L = C.ev || [], nb = v => v == null || v === "" ? "–" : (+v).toLocaleString("fr-FR");
  const sum = k => L.reduce((a, e) => a + (+e[k] || 0), 0), vis = sum("visiteurs"), con = sum("contacts"), ops = new Set(L.map(e => e.evenement)).size;
  const kpi = L.length ? `<div class="kpis3"><div class="kpi"><b>${ops}</b><span>événement${ops > 1 ? "s" : ""} · ${L.length} journée${L.length > 1 ? "s" : ""}</span></div><div class="kpi"><b>${nb(vis)}</b><span>visiteurs accueillis</span></div>
    <div class="kpi"><b>${nb(con)}</b><span>contacts collectés${vis ? " · " + Math.round(con / vis * 100) + " %" : ""}</span></div><div class="kpi"><b>${nb(sum("documents"))}</b><span>documents ou cadeaux remis</span></div></div>` : "";
  $("#view").innerHTML = vues() + `<div class="card accent"><h2>Rapports d'événement</h2><div class="muted">Le bilan de chaque journée d'accueil : affluence, visiteurs, contacts, déroulement, points forts, recommandations et photos.</div>
      <div class="row3"><label>Du<input type="date" id="ev-from" value="${C.f.from}"></label><label>Au<input type="date" id="ev-to" value="${C.f.to}"></label><span></span></div></div>${kpi}
    ${L.length ? `<div class="card"><h2>Bilans par opération</h2><div class="muted">Toutes les journées d'une opération réunies : chiffres, équipe, recommandations et photos.</div><div class="foc-l" style="margin-top:8px">${[...new Map(L.map(e => [e.devis_id, e])).values()].map(e => { const J = L.filter(x => x.devis_id === e.devis_id);
      return `<a class="foc-i" style="text-decoration:none" target="_blank" rel="noopener" href="marques_api.php?action=evt_bilan_pdf&devis_id=${e.devis_id}"><b>${esc(e.evenement || e.type || "")}</b><span class="hint">${J.length} journée${J.length > 1 ? "s" : ""} · ${nb(J.reduce((a, x) => a + (+x.visiteurs || 0), 0))} visiteurs · ${nb(J.reduce((a, x) => a + (+x.contacts || 0), 0))} contacts</span><span class="foc-go">Bilan (PDF) ›</span></a>`; }).join("")}</div></div>` : ""}
    ${L.length ? `<div class="card tablebox"><h2>Journée par journée</h2><table><tr><th>Date</th><th>Événement</th><th>Lieu</th><th class="num">Visiteurs</th><th class="num">Contacts</th><th></th></tr>
      ${L.map(e => `<tr><td>${esc(dfr(e.date_evt))}</td><td><b>${esc(e.evenement || e.type || "")}</b><div class="hint">${esc(e.affluence ? "affluence " + e.affluence.toLowerCase() : "")}</div></td><td>${esc(e.lieu)}</td><td class="num">${nb(e.visiteurs)}</td><td class="num">${nb(e.contacts)}</td>
        <td><a class="btn" target="_blank" rel="noopener" href="marques_api.php?action=evt_pdf&id=${e.id}">Rapport (PDF)</a></td></tr>`).join("")}</table></div>`
      : `<div class="card"><div class="empty">Aucun rapport d'événement sur cette période.</div></div>`}`;
  bindVues(); ["#ev-from", "#ev-to"].forEach(k => $(k).onchange = () => { C.f.from = $("#ev-from").value; C.f.to = $("#ev-to").value; loadEvtsC(); });
}

/* ---------- Merchandising ---------- */
const mcQ = () => new URLSearchParams({from:C.f.from, to:C.f.to, zone:C.f.zone || "", marques:C.f.marques.join(",")}).toString();
async function loadMcC(){ const [a, b] = await Promise.all([capi("mc", null, mcQ()), capi("mc_refs").catch(() => null)]); C.mc = a; C.cat = b; renderMcC(); }
/* Catalogue et PVP : la marque voit ses références (celles que l'agence relève sont marquées) et saisit ses prix de vente conseillés. */
const CK = { mq:0, q:"", lim:200, imp:null, chg:{} };
function vCat(){
  const K = C.cat; if (!K || !K.marques.length) return "";
  if (!K.marques.find(m => m.id === CK.mq)) CK.mq = K.marques[0].id; const M = K.marques.find(m => m.id === CK.mq), q = CK.q.trim().toLowerCase();
  const L = M.refs.filter(r => !q || [r.nom, r.gamme, r.ean, r.code].join(" ").toLowerCase().includes(q)), nr = M.refs.filter(r => r.a_relever).length, sp = M.refs.filter(r => r.prix == null).length;
  const px = r => CK.chg[r.id] != null ? CK.chg[r.id] : r.prix == null ? "" : (+r.prix).toFixed(2).replace(".", ",");
  return `<div class="card" id="cat-card"><h2>Votre catalogue et vos PVP</h2><div class="muted">Les références de votre marque suivies en magasin. Le PVP conseillé sert à mesurer l'écart avec le prix affiché en rayon : renseignez-le ici ou importez votre fichier de prix (Excel ou CSV avec une colonne « EAN » et une colonne « PVP »).</div>
    <div class="row3">${K.marques.length > 1 ? `<label>Marque<select id="ck-mq">${K.marques.map(m => `<option value="${m.id}" ${m.id === CK.mq ? "selected" : ""}>${esc(m.nom)}</option>`).join("")}</select></label>` : ""}<label>Rechercher<input id="ck-q" value="${esc(CK.q)}" placeholder="Désignation, gencod, code…"></label>
      <div class="hint" style="align-self:end"><b>${M.refs.length}</b> références · <b>${nr}</b> relevées par l'agence${sp ? ` · <b>${sp}</b> sans PVP` : ""}</div></div>
    ${K.peut ? `<div class="btns" style="margin-top:0"><label class="btn ghost">Importer mon fichier de prix<input type="file" id="ck-file" accept=".xlsx,.csv,.txt" hidden></label><button class="primary" id="ck-save" ${Object.keys(CK.chg).length ? "" : "disabled"}>Enregistrer mes PVP${Object.keys(CK.chg).length ? " (" + Object.keys(CK.chg).length + ")" : ""}</button></div>` : `<div class="hint">La saisie des PVP n'est pas ouverte sur votre compte : demandez-la à l'agence.</div>`}
    ${CK.imp ? `<div class="card" style="margin:10px 0"><b>${esc(CK.imp.fichier)}</b> : ${CK.imp.n} ligne${CK.imp.n > 1 ? "s" : ""} lue${CK.imp.n > 1 ? "s" : ""}. Colonnes repérées : ${["nom:Désignation", "ean:EAN", "code:Code article", "prix:PVP"].map(x => x.split(":")).filter(([k]) => CK.imp.map[k] != null).map(([k, l]) => `${l} = « ${esc(CK.imp.colonnes[CK.imp.map[k]])} »`).join(", ")}.
      <div class="hint">Les références connues (même gencod, code ou désignation) prennent le nouveau PVP ; les nouvelles sont ajoutées au catalogue et l'agence choisit lesquelles relever.</div>
      <div class="btns"><button class="primary" id="ck-imp-ok">Appliquer ce fichier</button><button class="ghost" id="ck-imp-x">Annuler</button></div></div>` : ""}
    <div class="tablebox"><table><tr><th>Référence</th><th>Gencod</th><th>Relevée</th><th class="num">PVP conseillé (€)</th></tr>
      ${L.slice(0, CK.lim).map(r => `<tr><td><b>${esc(r.nom)}</b><div class="hint">${esc([r.gamme, r.code].filter(Boolean).join(" · "))}</div></td><td>${esc(r.ean || "")}</td><td>${r.a_relever ? "oui" : "–"}</td>
        <td class="num">${K.peut ? `<input data-ckp="${r.id}" value="${esc(px(r))}" inputmode="decimal" style="width:90px;text-align:right">` : esc(px(r) || "–")}${r.pvp_par ? `<div class="hint">${/\(marque\)$/.test(r.pvp_par) ? "par vous" : "par l'agence"}${r.pvp_le ? " le " + esc(dfr(String(r.pvp_le).slice(0, 10))) : ""}</div>` : ""}</td></tr>`).join("")}</table></div>
    ${L.length > CK.lim ? `<div class="btns"><button class="ghost" id="ck-plus">Afficher plus (${L.length - CK.lim} restantes)</button></div>` : ""}</div>`;
}
function bCat(){
  if (!$("#cat-card")) return; const redo = f => { const el = $("#cat-card"); if (!el || !el.isConnected) return; const y = scrollY; el.outerHTML = vCat(); bCat(); scrollTo(0, y); if (f && $(f)) { const e = $(f); e.focus(); e.setSelectionRange && e.setSelectionRange(e.value.length, e.value.length); } };
  if ($("#ck-mq")) $("#ck-mq").onchange = e => { CK.mq = +e.target.value; CK.chg = {}; redo(); };
  $("#ck-q").oninput = e => { CK.q = e.target.value; CK.lim = 200; redo("#ck-q"); };
  if ($("#ck-plus")) $("#ck-plus").onclick = () => { CK.lim += 300; redo(); };
  // Saisie d'un PVP : notée sans redessiner la liste (le bouton compte les prix modifiés).
  document.querySelectorAll("[data-ckp]").forEach(i => i.oninput = () => { const r = C.cat.marques.find(m => m.id === CK.mq).refs.find(x => x.id === +i.dataset.ckp), v = i.value.trim();
    if (v === (r.prix == null ? "" : (+r.prix).toFixed(2).replace(".", ","))) delete CK.chg[r.id]; else CK.chg[r.id] = v;
    const b = $("#ck-save"), n = Object.keys(CK.chg).length; if (b) { b.disabled = !n; b.textContent = "Enregistrer mes PVP" + (n ? " (" + n + ")" : ""); } });
  if ($("#ck-save")) $("#ck-save").onclick = async () => { try { const r = await capi("mc_pvp", {marque:CK.mq, prix:CK.chg}); CK.chg = {}; toast(r.n + " PVP enregistré" + (r.n > 1 ? "s" : "")); C.cat = await capi("mc_refs"); redo(); } catch(e) { toast(e.message); } };
  const envoi = async (f, appliquer) => { const fd = new FormData(); fd.append("fichier", f); fd.append("marque", CK.mq); if (appliquer) fd.append("appliquer", "1");
    const r = await fetch("marques_api.php?action=mc_import", {method:"POST", credentials:"same-origin", headers:{"X-Requested-With":"amaya"}, body:fd}); const d = await r.json().catch(() => ({})); if (!r.ok) throw new Error(d.error || "Erreur " + r.status); return d; };
  if ($("#ck-file")) $("#ck-file").onchange = async e => { const f = e.target.files[0]; if (!f) return; try { const d = await envoi(f, false); CK.imp = Object.assign(d, {fichier:f.name, f}); redo(); } catch(x) { toast(x.message); } };
  if ($("#ck-imp-x")) $("#ck-imp-x").onclick = () => { CK.imp = null; redo(); };
  if ($("#ck-imp-ok")) $("#ck-imp-ok").onclick = async () => { try { const d = await envoi(CK.imp.f, true); CK.imp = null; C.cat = await capi("mc_refs");
    toast(`${d.maj} PVP mis à jour, ${d.ajout} nouvelle${d.ajout > 1 ? "s" : ""} référence${d.ajout > 1 ? "s" : ""}${d.invalides && d.invalides.length ? ", gencods invalides ignorés : " + d.invalides.join(", ") : ""}`); redo(); } catch(x) { toast(x.message); } };
}
function renderMcC(){
  const R = C.mc || {stats:{}, refs:[], pdv:[], avap:[], visites:0}, T = R.stats, pc = v => v == null ? "–" : String(v).replace(".", ",") + " %", eur2 = v => v == null ? "–" : (+v).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €";
  const k = (v, l) => `<div class="kpi"><b>${v}</b><span>${l}</span></div>`;
  $("#view").innerHTML = vues() + `<div class="card accent"><h2>Merchandising</h2><div class="muted">Les visites de nos merchandiseurs, relues et validées : présence de vos références, ruptures, prix relevés, linéaire, promotions et photos avant/après.</div>
      <div class="row3"><label>Du<input type="date" id="mc-from" value="${C.f.from}"></label><label>Au<input type="date" id="mc-to" value="${C.f.to}"></label><label>Secteur<select id="mc-zone"><option value="">Toute la France</option>${(C.s.compte.geo || []).map(([v, l]) => `<option value="${esc(v)}" ${C.f.zone === v ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></label></div>${pres()}</div>
    ${R.visites ? `<div class="kpis3">${k(R.visites, "visites")}${k(T.pdv, "points de vente")}${k(pc(T.distribution), "distribution")}${k(pc(T.taux_rupture), "taux de rupture")}${k(pc(T.conformite_prix), "prix conformes (±2 %)")}${k(pc(T.part_lineaire), "part de linéaire")}</div>
      ${can("telecharger") ? `<div class="card"><div class="btns" style="margin:0"><a class="btn" target="_blank" rel="noopener" href="marques_api.php?action=mc_pdf&${mcQ()}">Rapport (PDF)</a><a class="btn" href="marques_api.php?action=mc_xlsx&${mcQ()}">Relevés (Excel)</a></div></div>` : ""}
      <div class="card tablebox"><h2>Vos références</h2><table><tr><th>Référence</th><th class="num">Distribution</th><th class="num">Rupture</th><th class="num">PVP conseillé</th><th class="num">Prix relevé</th><th class="num">Facings</th></tr>
        ${R.refs.map(q => `<tr><td><b>${esc(q.nom)}</b><div class="hint">${esc(q.gamme || "")}</div></td><td class="num">${pc(q.dn)}</td><td class="num">${pc(q.tx_rupture)}</td><td class="num">${eur2(q.conseille)}</td><td class="num">${eur2(q.prix_moy)}</td><td class="num">${q.facings_moy == null ? "–" : String(q.facings_moy).replace(".", ",")}</td></tr>`).join("")}</table></div>
      <div class="card tablebox"><h2>Par point de vente</h2><table><tr><th>Point de vente</th><th class="num">Visites</th><th>Dernière</th><th class="num">Ruptures</th><th class="num">Non référencées</th><th>Promo</th></tr>
        ${R.pdv.map(q => `<tr><td><b>${esc(q.nom)}</b><div class="hint">${esc(q.ville || "")}</div></td><td class="num">${q.visites}</td><td>${esc(dfr(q.derniere))}</td><td class="num">${q.rupture}</td><td class="num">${q.absent}</td><td>${q.promo ? "oui" : "non"}</td></tr>`).join("")}</table></div>
      ${R.avap.length && can("photos") ? `<div class="card"><h2>Avant / après</h2><div class="mc-avap">${R.avap.map(x => `<div class="mc-pair"><a href="${x.avant_full}" target="_blank" rel="noopener"><img src="${x.avant}" alt="Avant"></a><a href="${x.apres_full}" target="_blank" rel="noopener"><img src="${x.apres}" alt="Après"></a><div class="hint">${esc(x.magasin)} · ${esc(dfr(x.date))}</div></div>`).join("")}</div></div>` : ""}`
    : `<div class="card"><div class="empty">Aucune visite validée sur cette période.</div></div>`}${vCat()}`;
  bindVues(); bCat(); const re = () => { C.f.from = $("#mc-from").value; C.f.to = $("#mc-to").value; C.f.zone = $("#mc-zone").value; loadMcC(); };
  ["#mc-from", "#mc-to", "#mc-zone"].forEach(x => $(x).onchange = re);
  document.querySelectorAll("[data-pre]").forEach(x => x.onclick = () => { const [a, z] = preset(x.dataset.pre); C.f.from = iso(a); C.f.to = iso(z); loadMcC(); });
}

/* ---------- Photothèque ---------- */
const phQ = () => new URLSearchParams({source:C.ph.source, modele:C.ph.source, from:C.f.from, to:C.f.to, marques:C.f.marques.join(","), zone:C.f.zone, magasin:C.ph.magasin, qui:C.ph.qui}).toString();
async function loadPh(){
  if (!C.ph.source) C.ph.source = C.s.compte.evts ? "evts" : "anim";
  C.ph.d = await capi("phototheque", null, phQ()); renderPh();
}
function renderPh(){
  const d = C.ph.d, P = C.ph, sel = P.sel;
  const srcs = [...(C.s.compte.evts ? [["evts", "Événements"]] : []), ...(C.s.marques.length ? [["anim", "Animations"]] : [])];
  $("#view").innerHTML = vues() + `<div class="card accent"><h2>Photothèque</h2><div class="muted">Les photos des journées validées. Cliquez sur une photo pour l'agrandir et la télécharger, ou cochez-en plusieurs pour un ZIP.</div>
    <div class="row3"><label>Du<input type="date" id="c-from" value="${C.f.from}"></label><label>Au<input type="date" id="c-to" value="${C.f.to}"></label>
      ${srcs.length > 1 ? `<label>Photos de<select id="p-src">${srcs.map(([k, l]) => `<option value="${k}" ${P.source === k ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></label>` : "<span></span>"}</div>${pres()}
    <div class="row3"><label>${P.source === "evts" ? "Lieu" : "Point de vente"}<select id="p-mag"><option value="">Tous</option>${d.options.magasins.map(m => `<option ${P.magasin === m ? "selected" : ""}>${esc(m)}</option>`).join("")}</select></label>
      <label>Intervenant(e)<select id="p-qui"><option value="">Toutes</option>${d.options.personnes.filter(Boolean).map(m => `<option ${P.qui === m ? "selected" : ""}>${esc(m)}</option>`).join("")}</select></label><span></span></div></div>
  <div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>${d.total} photo${d.total > 1 ? "s" : ""}${d.total > d.photos.length ? ` <span class="hint">· ${d.photos.length} premières affichées, réduisez la période</span>` : ""}</h2>
    <div class="btns" style="margin:0"><button class="ghost" id="p-all">Tout cocher</button><button class="ghost" id="p-none">Tout décocher</button>${can("telecharger") ? `<button class="primary" id="p-zip" ${sel.size ? "" : "disabled"}>Télécharger la sélection (${sel.size})</button>` : ""}</div></div>
    ${d.photos.length ? `<div class="cph-grid">${d.photos.map(p => `<div class="cph${sel.has(p.id) ? " on" : ""}"><button type="button" class="cph-img" data-pv="${p.id}"><img loading="lazy" src="${p.url}" alt=""></button>
      <label class="cph-ck"><input type="checkbox" data-ps="${p.id}" ${sel.has(p.id) ? "checked" : ""}></label>
      <div class="cph-t"><b>${esc(p.magasin)}</b><span>${esc(dfr(p.date))}${p.qui ? " · " + esc(p.qui) : ""}</span></div></div>`).join("")}</div>`
      : `<div class="empty">Aucune photo validée sur cette sélection.</div>`}</div>`;
  bindVues();
  const re = () => { C.f.from = $("#c-from").value; C.f.to = $("#c-to").value; loadPh(); };
  $("#c-from").onchange = re; $("#c-to").onchange = re;
  document.querySelectorAll("[data-pre]").forEach(x => x.onclick = () => { const [a, z] = preset(x.dataset.pre); C.f.from = iso(a); C.f.to = iso(z); loadPh(); });
  if ($("#p-src")) $("#p-src").onchange = e => { P.source = e.target.value; P.magasin = ""; P.qui = ""; sel.clear(); loadPh(); };
  $("#p-mag").onchange = e => { P.magasin = e.target.value; loadPh(); };
  $("#p-qui").onchange = e => { P.qui = e.target.value; loadPh(); };
  $("#p-all").onclick = () => { d.photos.forEach(p => sel.add(p.id)); renderPh(); };
  $("#p-none").onclick = () => { sel.clear(); renderPh(); };
  document.querySelectorAll("[data-ps]").forEach(i => i.onchange = () => { i.checked ? sel.add(+i.dataset.ps) : sel.delete(+i.dataset.ps); renderPh(); });
  if ($("#p-zip")) $("#p-zip").onclick = () => { location.href = "marques_api.php?action=phototheque_zip&" + phQ() + "&ids=" + [...sel].join(","); };
  document.querySelectorAll("[data-pv]").forEach(b => b.onclick = () => phView(+b.dataset.pv));
}
function phView(id){
  const L = C.ph.d.photos, i = L.findIndex(p => p.id === id); if (i < 0) return; const p = L[i];
  document.getElementById("cl-lb")?.remove();
  const box = document.createElement("div"); box.id = "cl-lb"; box.className = "cl-lb";
  box.innerHTML = `<div class="cl-lb-in"><img src="${p.full}" alt="" style="width:100%;border-radius:10px;display:block">
    <div style="margin:10px 0"><b>${esc(p.magasin)}</b>${p.ville ? " · " + esc(p.ville) : ""}<div class="muted">${esc(dfr(p.date))}${p.qui ? " · " + esc(p.qui) : ""}${p.marque ? " · " + esc(p.marque) : ""}</div></div>
    <div class="btns">${i > 0 ? `<button class="ghost" data-nav="${L[i - 1].id}">← Précédente</button>` : ""}${can("telecharger") ? `<a class="btn primary" href="${p.full}" download="${esc(p.nom)}">Télécharger cette photo</a>` : ""}${i < L.length - 1 ? `<button class="ghost" data-nav="${L[i + 1].id}">Suivante →</button>` : ""}<button class="ghost" data-x>Fermer</button></div></div>`;
  box.onclick = e => { const n = e.target.closest("[data-nav]"); if (n) return phView(+n.dataset.nav); if (e.target === box || e.target.closest("[data-x]")) box.remove(); };
  document.body.appendChild(box);
}
function tbl(head, rows){ return `<div class="tablewrap"><table><tr>${head.map(h => `<th>${h}</th>`).join("")}</tr>${rows.join("")}</table></div>`; }
function render(){
  const d = C.d, s = C.s, k = d.kpi, ca = d.ca_visible, f = C.f;
  const dl = (a, extra) => "marques_api.php?action=" + a + "&" + q() + (extra ? "&" + extra : "");
  const moisLab = m => new Date(m + "-15").toLocaleDateString("fr-FR", {month:"long", year:"numeric"});
  $("#view").innerHTML = vues() + vMr() + `<div class="card accent"><h2>${esc(s.compte.societe || s.compte.nom)}</h2>
    <div class="row3"><label>Du<input type="date" id="c-from" value="${f.from}"></label><label>Au<input type="date" id="c-to" value="${f.to}"></label>
      ${(s.compte.geo || []).length ? `<label>Secteur<select id="c-zone"><option value="">Toute la France</option>${s.compte.geo.map(([v, l]) => `<option value="${esc(v)}" ${f.zone === v ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></label>` : "<span></span>"}</div>
    <div class="presets">${[["sem1","Semaine dernière"],["mois","Ce mois-ci"],["mois1","Mois dernier"],["an","Depuis le 1er janvier"]].map(([v, l]) => `<button type="button" class="chip" data-pre="${v}">${l}</button>`).join("")}</div>
    ${s.marques.length > 1 ? `<div><div class="muted" style="margin:8px 0 4px">Marques</div><div class="chips multi" id="c-mk">${s.marques.map(m => `<button type="button" class="chip" data-v="${m.id}" aria-pressed="${f.marques.includes(m.id)}">${esc(m.nom)}</button>`).join("")}</div></div>` : ""}
    <div class="row3"><label>Point de vente<select id="c-mag"><option value="">Tous</option>${d.options.magasins.map(m => `<option value="${esc(m.nom)}" ${f.magasin === m.nom ? "selected" : ""}>${esc(m.nom)}${m.ville ? " · " + esc(m.ville) : ""}</option>`).join("")}</select></label>
      <label>Intervenant(e)<select id="c-anim"><option value="">Toutes</option>${d.options.animatrices.map(a => `<option value="${a.id}" ${String(f.animatrice) === String(a.id) ? "selected" : ""}>${esc(a.nom)}</option>`).join("")}</select></label><span></span></div></div>
  <div class="kpis3">
    <div class="kpi"><b>${k.jours}</b><span>journées d'animation</span></div>
    ${ca ? `<div class="kpi"><b>${eur(k.ca)}</b><span>CA réalisé</span></div><div class="kpi"><b>${k.jours ? eur(k.ca / k.jours) : "–"}</b><span>CA moyen par journée</span></div>
      ${k.obj > 0 ? `<div class="kpi"><b>${eur(k.obj)}</b><span>objectifs (journées avec objectif)</span></div><div class="kpi"><b>${pct(k.ca_obj, k.obj)}</b><span>atteinte des objectifs</span></div>` : ""}` : ""}
  </div>
  ${can("telecharger") ? `<div class="card"><h2>Télécharger</h2>
    <div class="row3"><label>Format du tableau Excel<select id="c-xfmt"><option value="mois" ${C.xfmt === "mois" ? "selected" : ""}>Un onglet par mois</option><option value="annee" ${C.xfmt === "annee" ? "selected" : ""}>Un onglet par intervenant(e) sur l'année</option></select></label>
      <label class="ck" style="align-self:end"><input type="checkbox" id="c-xmag" ${C.xmag ? "checked" : ""}><span>Un fichier par point de vente</span></label><span></span></div>
    <div class="btns"><a class="btn" href="${dl("xlsx", "xfmt=" + C.xfmt + (C.xmag ? "&xmag=1" : ""))}">Tableau Excel</a><a class="btn" target="_blank" rel="noopener" href="${dl("pdf", "type=cr")}">Rapport PDF</a>
      <a class="btn" target="_blank" rel="noopener" href="${dl("pdf", "type=analyse")}">Analyse des ventes (PDF)</a>${can("photos") ? `<a class="btn" href="${dl("photos")}">Photos (ZIP)</a>` : ""}</div>
    <div class="hint">Les fichiers reprennent exactement la sélection ci-dessus (période, marques, point de vente, intervenant(e)).</div></div>` : ""}
  ${ca && d.rows.length ? `<div class="grid2"><div class="card"><h2>Par mois</h2>${tbl(["Mois","Journées","CA"], d.par_mois.map(x => `<tr><td>${esc(moisLab(x.nom))}</td><td>${x.jours}</td><td class="num">${eur(x.ca)}</td></tr>`))}</div>
    <div class="card"><h2>Par marque</h2>${tbl(["Marque","Journées","CA","Atteinte"], d.par_marque.map(x => `<tr><td>${esc(x.nom)}</td><td>${x.jours}</td><td class="num">${eur(x.ca)}</td><td>${pct(x.ca_obj, x.obj)}</td></tr>`))}</div>
    <div class="card"><h2>Par point de vente</h2>${tbl(["Point de vente","Journées","CA","Moyenne"], d.par_magasin.map(x => `<tr><td>${esc(x.nom)}</td><td>${x.jours}</td><td class="num">${eur(x.ca)}</td><td class="num">${eur(x.ca / x.jours)}</td></tr>`))}</div>
    <div class="card"><h2>Par intervenant(e)</h2>${tbl(["Intervenant(e)","Journées","CA","Moyenne"], d.par_animatrice.map(x => `<tr><td>${esc(x.nom)}</td><td>${x.jours}</td><td class="num">${eur(x.ca)}</td><td class="num">${eur(x.ca / x.jours)}</td></tr>`))}</div></div>` : ""}
  <div class="card"><h2>Journées d'animation (${d.rows.length})</h2>
    ${d.rows.length ? tbl(["Date","Point de vente","Intervenant(e)","Marque", ...(ca ? ["CA","Objectif","Atteinte"] : []), ...(can("photos") ? ["Photos"] : [])], d.rows.map(r => {
      const ob = r.lignes.reduce((a, l) => l.objectif != null ? a + l.objectif : a, 0), nph = r.lignes.reduce((a, l) => a + l.photos.length, 0);
      return `<tr class="click" data-r="${r.id}"><td>${esc(dfr(r.date))}</td><td>${esc(r.magasin)}<br><span class="muted">${esc(r.ville)}</span></td><td>${esc(r.animatrice)}</td><td>${esc(r.lignes.map(l => l.marque).join(", "))}</td>
        ${ca ? `<td class="num">${eur(r.ca)}</td><td class="num">${ob > 0 ? eur(ob) : ""}</td><td>${pct(r.ca, ob)}</td>` : ""}${can("photos") ? `<td>${nph || ""}</td>` : ""}</tr>`; }))
      : `<div class="empty">Aucune journée validée sur cette sélection. Les comptes rendus apparaissent ici une fois relus et validés par ${AGN}.</div>`}</div>`;
  bind();
}
function bind(){
  bindVues(); bindMr();
  const re = () => { C.f.from = $("#c-from").value; C.f.to = $("#c-to").value; if ($("#c-zone")) C.f.zone = $("#c-zone").value; C.f.magasin = $("#c-mag").value; C.f.animatrice = $("#c-anim").value; load(); };
  ["#c-from","#c-to","#c-zone","#c-mag","#c-anim"].forEach(s => { const el = $(s); if (el) el.onchange = re; });
  document.querySelectorAll("[data-pre]").forEach(b => b.onclick = () => { const [a, z] = preset(b.dataset.pre); C.f.from = iso(a); C.f.to = iso(z); load(); });
  if ($("#c-mk")) $("#c-mk").onclick = e => { const b = e.target.closest(".chip"); if (!b) return; const v = +b.dataset.v;
    C.f.marques = C.f.marques.includes(v) ? C.f.marques.filter(x => x !== v) : C.f.marques.concat(v); if (!C.f.marques.length) C.f.marques = [v]; load(); };
  if ($("#c-xfmt")) $("#c-xfmt").onchange = e => { C.xfmt = e.target.value; render(); };
  if ($("#c-xmag")) $("#c-xmag").onchange = e => { C.xmag = e.target.checked; render(); };
  document.querySelectorAll("[data-r]").forEach(tr => tr.onclick = () => detail(+tr.dataset.r));
}
/** Compte rendu complet d'une journée. */
function detail(id){
  const r = C.d.rows.find(x => x.id === id); if (!r) return; const ca = C.d.ca_visible;
  const sec = (t, v) => v && String(v).trim() ? `<div class="cr-sec"><div class="cr-h">${t}</div><div class="cr-t">${esc(v).replace(/\n/g, "<br>")}</div></div>` : "";
  document.getElementById("cl-lb")?.remove();
  const box = document.createElement("div"); box.id = "cl-lb"; box.className = "cl-lb";
  box.innerHTML = `<div class="cl-lb-in"><div class="cr">
    <div class="cr-hero"><div class="cr-k">Compte rendu d'animation</div><div class="cr-title">${esc(r.magasin)}</div><div class="cr-sub">${esc(dfr(r.date))} · ${esc(r.ville)} · ${esc(r.animatrice)}</div></div>
    ${r.lignes.map(l => `<div class="cr-sec"><div class="cr-h">${esc(l.marque)}</div>
      ${ca ? `<div class="cr-kpis">${(l.parts && l.parts.length ? l.parts : [{nom:"CA", ca:l.ca, objectif:l.objectif}]).map(p => `<div><b>${eur(p.ca)}</b><span>${esc(p.nom === "CA" ? "CA réalisé" : "CA " + p.nom)}${p.objectif > 0 ? " · objectif " + eur(p.objectif) + " · " + pct(p.ca, p.objectif) : ""}</span></div>`).join("")}</div>` : ""}
      ${sec("Réactions des clientes", l.reactions)}${sec("Ruptures", l.ruptures)}${sec("Testeurs manquants", l.testeurs)}
      ${l.photos.length ? `<div class="cr-ph">${l.photos.map(p => `<a href="${p.full}" target="_blank" rel="noopener"><img src="${p.url}" alt=""></a>`).join("")}</div>` : ""}</div>`).join("")}
    ${r.frequentation ? sec("Fréquentation", r.frequentation) : ""}${r.concurrence.length ? sec("Intervenants concurrent(e)s présentes", r.concurrence.join(", ")) : ""}${sec("Offres en cours", r.offres)}${sec("Commentaires", r.faits)}
    </div><div class="btns"><button class="ghost" data-x>Fermer</button></div></div>`;
  box.onclick = e => { if (e.target === box || e.target.closest("[data-x]")) box.remove(); };
  document.body.appendChild(box);
}
boot();
