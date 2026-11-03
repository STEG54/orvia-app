// ORVIA : administration de la conciergerie (Aujourd'hui, Logements, Réservations, Missions, Signalements, Propriétaires).
const CZ = { ini:null, jour:null, logs:null, ed:null, res:null, resF:{ from:"", to:"", logement:"" }, det:null, mis:null, misF:{ from:"", to:"" }, mdet:null, inc:null, incS:"ouvert", prop:null, lien:null, decl:null, rap:null, rapF:null, plan:null, planD:"", avis:null, srv:null, srvEd:null };
const CZ_SUBS = [["cz_jour","Aujourd'hui"],["cz_planning","Planning"],["cz_reservations","Réservations"],["cz_missions","Missions"],["cz_logements","Logements"],["cz_incidents","Signalements"],["cz_proprietaires","Propriétaires"],["cz_rapports","Rapports"],["cz_taxe","Taxe de séjour"],["cz_avis","Avis"],["cz_services","Services"],["cz_intendance","Linge et entretien"],["cz_equipe","Équipe"],["cz_paie","Paiements"]];
async function czapi(action, body, query){
  const url = "conciergerie_api.php?action=" + action + (query ? "&" + query : "");
  const r = await fetch(url, body ? {method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/json","X-Requested-With":"amaya"}, body:JSON.stringify(body)} : {credentials:"same-origin"});
  if (typeof sessionExpiree === "function") sessionExpiree(r);
  const d = await r.json().catch(() => ({})); if (!r.ok) { const e = new Error(d.error || "Erreur " + r.status); e.status = r.status; throw e; } return d;
}
const czIso = d => { const x = new Date(d); x.setMinutes(x.getMinutes() - x.getTimezoneOffset()); return x.toISOString().slice(0, 10); };
const czAdd = (iso, n) => { const d = new Date(iso + "T12:00:00"); d.setDate(d.getDate() + n); return czIso(d); };
const czD = iso => iso ? new Date(String(iso).slice(0, 10) + "T12:00:00").toLocaleDateString("fr-FR", {weekday:"short", day:"numeric", month:"short"}) : "";
const czFv = fv => !fv ? `<span class="pill">Annulée</span>` : fv.ok ? `<span class="pill p-ok">Feu vert</span>` : `<span class="pill p-warn" title="${esc(fv.bloque.join(" · "))}">En attente</span>`;
const czSt = s => `<span class="pill ${s === "faite" ? "p-ok" : s === "refusee" ? "p-bad" : s === "en_cours" ? "p-warn" : ""}">${esc((CZ.ini.statuts || {})[s] || s)}</span>`;
const czWa = (url, txt) => url ? `<a class="btn" href="${esc(url)}" target="_blank" rel="noopener">WhatsApp · ${esc(txt)}</a>` : "";
const czWaBloc = wa => !wa || !wa.tel ? `<span class="hint">Pas de numéro WhatsApp : le voyageur peut l'indiquer sur son lien.</span>` : czWa(wa.fiche, "fiche") + czWa(wa.code, "code") + czWa(wa.nouvelles, "nouvelles");
const czArr = r => r.heure ? `${r.mode ? esc((CZ.ini.modes || {})[r.mode] || r.mode) + " · " : ""}${r.vol ? "vol " + esc(r.vol) + " · " : ""}arrivée ${r.heure_connue === false ? "vers" : "à"} ${esc(r.heure)}` : "";
const czDH = v => (+v || 0).toLocaleString("fr-FR", {maximumFractionDigits:2}) + " DH";
async function czCopier(txt, msg){ try { await navigator.clipboard.writeText(txt); toast(msg || "Copié"); } catch(e) { prompt("Copiez :", txt); } }

async function czLoad(){
  if (CZ.vu !== G.sub) { CZ.vu = G.sub; CZ.det = null; CZ.mdet = null; CZ.ed = null; CZ.lien = null; CZ.decl = null; }
  if (!CZ.ini) CZ.ini = await czapi("init");
  if (!CZ.resF.from) { const t = czIso(new Date()); CZ.resF.from = czAdd(t, -3); CZ.resF.to = czAdd(t, 30); CZ.misF.from = t; CZ.misF.to = czAdd(t, 7); }
  if (!CZ.planD) CZ.planD = czIso(new Date());
  if (G.sub === "cz_planning") CZ.plan = await czapi("planning", null, "date=" + CZ.planD);
  if (G.sub === "cz_rapports") { if (!CZ.rapF) { const p = czPeriode("30j"); CZ.rapF = {from:p[0], to:p[1], par:"logement", k:"30j"}; } CZ.rap = await czapi("rapport", null, `from=${CZ.rapF.from}&to=${CZ.rapF.to}&par=${CZ.rapF.par}`); }
  if (G.sub === "cz_avis") CZ.avis = await czapi("avis");
  if (G.sub === "cz_services") CZ.srv = await czapi("services");
  if (G.sub === "cz_equipe") CZ.eq = await czapi("equipe");
  if (G.sub === "cz_paie") { CZ.paM = CZ.paM || (new Date().getDate() <= 7 ? czIso(new Date(new Date().getFullYear(), new Date().getMonth(), 0)).slice(0, 7) : czIso(new Date()).slice(0, 7)); CZ.pa = await czapi("paie", null, "mois=" + CZ.paM); }
  if (G.sub === "cz_intendance") CZ.int = await czapi("intendance");
  if (G.sub === "cz_taxe") { if (!CZ.tsM) { const d = new Date(); d.setDate(0); CZ.tsM = czIso(d).slice(0, 7); } const [y, m] = CZ.tsM.split("-").map(Number); CZ.ts = await czapi("taxe_sejour", null, `from=${CZ.tsM}-01&to=${czIso(new Date(y, m, 0))}`); }
  if (G.sub === "cz_jour") CZ.jour = await czapi("aujourdhui");
  if (G.sub === "cz_reservations") CZ.res = (await czapi("reservations", null, `from=${CZ.resF.from}&to=${CZ.resF.to}&logement=${CZ.resF.logement}`)).reservations;
  if (G.sub === "cz_missions") CZ.mis = (await czapi("missions", null, `from=${CZ.misF.from}&to=${CZ.misF.to}`)).missions;
  if (G.sub === "cz_incidents") CZ.inc = (await czapi("incidents", null, "statut=" + CZ.incS)).incidents;
  if (G.sub === "cz_logements" || G.sub === "cz_proprietaires") CZ.ini = await czapi("init");
  if (G.sub === "cz_proprietaires" && CZ.pfId) CZ.pf = await czapi("prop_fiche", null, "id=" + CZ.pfId);
}
function czView(){
  const v = {cz_jour:czVJour, cz_logements:czVLogements, cz_reservations:czVRes, cz_missions:czVMis, cz_incidents:czVInc, cz_proprietaires:czVProp, cz_planning:czVPlan, cz_rapports:czVRap, cz_avis:czVAvis, cz_services:czVSrv, cz_equipe:czVEq, cz_taxe:czVTs, cz_intendance:czVInt, cz_paie:czVPaie}[G.sub];
  return v ? v() : "";
}
function czBind(){
  const b = {cz_jour:czBJour, cz_logements:czBLogements, cz_reservations:czBRes, cz_missions:czBMis, cz_incidents:czBInc, cz_proprietaires:czBProp, cz_planning:czBPlan, cz_rapports:czBRap, cz_avis:czBAvis, cz_services:czBSrv, cz_equipe:czBEq, cz_taxe:czBTs, cz_intendance:czBInt, cz_paie:czBPaie}[G.sub];
  if (b) b();
}
const czRe = () => go("gestion");

/* ---------- Aujourd'hui ---------- */
function czVJour(){
  const J = CZ.jour, auj = czIso(new Date());
  const arr = J.arrivees.map(r => `<div class="card" style="display:grid;gap:8px">
      <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><b>${esc(r.logement)}</b>${czFv(r.fv)}</div>
      <div class="muted">${r.arrivee === auj ? "Arrivée aujourd'hui" : "Arrivée demain"}${czArr(r) ? " · " + czArr(r) : ""} · départ ${esc(czD(r.depart))} · ${esc((CZ.ini.sources || {})[r.source] || r.source)}</div>
      <div class="hint">Fiches ${r.fv.nb_fiches}/${r.fv.attendues} · ménage ${r.fv.menage ? "validé" : "à faire"} · ${r.fv.incidents ? r.fv.incidents + " incident(s) bloquant(s)" : "aucun incident bloquant"}${r.code_envoye ? " · code envoyé" : ""}</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn" data-lien="${esc(r.lien)}">Copier le lien voyageur</button>${!r.code_envoye && r.fv.ok ? `<button class="primary" data-code="${r.id}">Envoyer le code</button>` : ""}<button class="btn ghost" data-det="${r.id}">Détail</button></div><div style="display:flex;gap:8px;flex-wrap:wrap">${czWaBloc(r.wa)}</div></div>`).join("");
  const mis = J.missions.map(m => `<tr data-mis="${m.id}" style="cursor:pointer"><td>${m.date < auj ? `<span class="pill p-bad">En retard</span> ` : ""}${esc(m.heure || "")}</td><td>${esc(m.logement)}</td><td>${esc((CZ.ini.types || {})[m.type] || m.type)}</td><td>${esc(m.agent || "Non attribuée")}</td><td>${czSt(m.statut)}</td></tr>`).join("");
  const inc = J.incidents.map(i => `<li>${i.bloquant == 1 ? `<span class="pill p-bad">Bloquant</span> ` : ""}<b>${esc(i.logement)}</b> · ${esc(i.description)}</li>`).join("");
  const qs = (J.questions || []).map(q => `<li><b>${esc(q.logement)}</b> · ${esc(q.texte)} <button class="btn ghost" data-det="${q.reservation_id}">Répondre</button></li>`).join("");
  const D = J.demarrage, suiv = D ? D.etapes.find(e => !e.fait) : null;
  const dem = D ? `<div class="card cz-dem"><div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap"><h2 style="margin:0">Bien démarrer avec Keylia</h2><span class="hint">${D.faits} sur ${D.total} étapes · <a href="#" id="demx">masquer</a></span></div>
    <div class="cz-dem-p"><i style="width:${Math.round(100 * D.faits / D.total)}%"></i></div>
    <ol class="cz-dem-l">${D.etapes.map(e => `<li class="${e.fait ? "ok" : e === suiv ? "now" : ""}"><span class="ic">${e.fait ? "✓" : ""}</span><div><b>${esc(e.titre)}</b>${e.fait ? "" : `<div class="hint">${esc(e.aide)}</div>`}</div>${e.fait ? "" : `<button class="${e === suiv ? "primary" : "btn ghost"}" data-dem="${e.onglet}">${e === suiv ? "Commencer" : "Ouvrir"}</button>`}</li>`).join("")}</ol></div>` : "";
  const pm = (J.prop_messages || []).map(q => `<li><b>${esc(q.nom)}</b> · ${esc(String(q.texte).slice(0, 140))} <button class="btn ghost" data-pmo="${q.proprietaire_id}">Répondre</button></li>`).join("");
  const arrA = J.arrivees.filter(r => r.arrivee === auj).length, misA = J.missions.filter(m => m.statut !== "faite").length, aTraiter = (J.questions || []).length + (J.prop_messages || []).length + J.incidents.filter(i => i.bloquant == 1).length;
  const qui = ((typeof A !== "undefined" && A.L && A.L.moi) || {}).nom || "";
  const tete = `<div class="ky-jour"><div><div class="ky-date">${esc(new Date().toLocaleDateString("fr-FR", {weekday:"long", day:"numeric", month:"long"}))}</div><div class="ky-salut">Bonjour${qui ? " " + esc(qui.split(" ")[0]) : ""}.</div>
      <div class="ky-phrase">${arrA || misA ? `${arrA ? `${arrA} arrivée${arrA > 1 ? "s" : ""} aujourd'hui` : "Aucune arrivée aujourd'hui"}, ${misA} mission${misA > 1 ? "s" : ""} en cours ou à faire.` : "Journée calme : rien de prévu aujourd'hui."}${aTraiter ? ` ${aTraiter} point${aTraiter > 1 ? "s" : ""} à traiter ci-dessous.` : " Tout est à jour."}</div></div>
    <div class="ky-chiffres"><div><b>${arrA}</b><span>arrivées</span></div><div><b>${misA}</b><span>missions</span></div><div><b>${aTraiter}</b><span>à traiter</span></div></div></div>`;
  return `${tete}${dem}${pm ? `<div class="card accent"><h2>Messages des propriétaires</h2><ul>${pm}</ul></div>` : ""}${qs ? `<div class="card accent"><h2>Questions des voyageurs à traiter</h2><ul>${qs}</ul></div>` : ""}<div class="card"><h2>Arrivées</h2>${arr || `<div class="muted">Aucune arrivée aujourd'hui ni demain.</div>`}</div>
    <div class="card"><h2>Missions du jour</h2>${mis ? `<div class="scroll"><table class="tbl"><thead><tr><th>Heure</th><th>Logement</th><th>Type</th><th>Équipe</th><th>État</th></tr></thead><tbody>${mis}</tbody></table></div>` : `<div class="muted">Aucune mission aujourd'hui.</div>`}</div>
    <div class="card"><h2>Signalements ouverts</h2>${inc ? `<ul>${inc}</ul><button class="btn" data-gs="cz_incidents">Tous les signalements</button>` : `<div class="muted">Aucun signalement ouvert.</div>`}</div>`;
}
function czBJour(){
  document.querySelectorAll("[data-lien]").forEach(b => b.onclick = () => czCopier(b.dataset.lien, "Lien copié : envoyez-le au voyageur par la messagerie de la plateforme."));
  document.querySelectorAll("[data-code]").forEach(b => b.onclick = () => czCode(+b.dataset.code));
  document.querySelectorAll("[data-det]").forEach(b => b.onclick = () => czDetail(+b.dataset.det));
  document.querySelectorAll("[data-mis]").forEach(b => b.onclick = () => czMission(+b.dataset.mis));
  document.querySelectorAll("[data-pmo]").forEach(b => b.onclick = () => { CZ.pfId = +b.dataset.pmo; G.sub = "cz_proprietaires"; G.ed = null; go("gestion"); });
  document.querySelectorAll("[data-dem]").forEach(b => b.onclick = () => { const k = b.dataset.dem; G.sub = k; G.ed = null; go("gestion"); });
  if ($("#demx")) $("#demx").onclick = async e => { e.preventDefault(); try { await czapi("demarrage_masquer", {}); czRe(); } catch(x) { toast(x.message); } };
}
async function czCode(id, forcer){
  try { const r = await czapi("code_envoyer", {id, forcer: !!forcer});
    if (!r.envoye) return toast("Feu vert pas encore donné : " + r.bloque.join(", "));
    toast(r.email ? "Code envoyé à " + r.email : "Aucun e-mail voyageur : transmettez le code " + (r.code || "") + " par la plateforme."); czRe();
  } catch(e) { toast(e.message); }
}

/* ---------- Réservations ---------- */
function czVRes(){
  if (CZ.det) return czVDetail();
  const L = CZ.ini.logements, rows = (CZ.res || []).map(r => `<tr><td>${esc(r.logement)}<div class="hint">${esc(r.ville)}</div></td><td>${esc(czD(r.arrivee))}</td><td>${esc(czD(r.depart))}</td><td>${r.nb}</td><td>${esc((CZ.ini.sources || {})[r.source] || r.source)}</td>
    <td>${czFv(r.fv)}${r.fv ? `<div class="hint">Fiches ${r.fv.nb_fiches}/${r.fv.attendues}</div>` : ""}</td><td style="white-space:nowrap">${r.lien ? `<button class="btn" data-lien="${esc(r.lien)}">Lien</button> ` : ""}<button class="btn ghost" data-det="${r.id}">Détail</button></td></tr>`).join("");
  return `<div class="card"><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      <label>Du<input type="date" id="rf" value="${CZ.resF.from}"></label><label>Au<input type="date" id="rt" value="${CZ.resF.to}"></label>
      <label>Logement<select id="rl"><option value="">Tous</option>${L.map(l => `<option value="${l.id}" ${+CZ.resF.logement === l.id ? "selected" : ""}>${esc(l.nom)}</option>`).join("")}</select></label>
      <button class="btn" id="rgo">Afficher</button>${CZ.ini.gerer ? `<button class="primary" id="rnew">Réservation directe</button>` : ""}</div></div>
    <div class="card">${rows ? `<div class="scroll"><table class="tbl"><thead><tr><th>Logement</th><th>Arrivée</th><th>Départ</th><th>Voyageurs</th><th>Canal</th><th>Feu vert</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>` : `<div class="muted">Aucune réservation sur la période. Ajoutez les calendriers Airbnb ou Booking dans la fiche de chaque logement.</div>`}</div>`;
}
function czBRes(){
  if (CZ.det) return czBDetail();
  $("#rgo").onclick = () => { CZ.resF = {from:$("#rf").value, to:$("#rt").value, logement:$("#rl").value}; czRe(); };
  document.querySelectorAll("[data-lien]").forEach(b => b.onclick = () => czCopier(b.dataset.lien, "Lien voyageur copié."));
  document.querySelectorAll("[data-det]").forEach(b => b.onclick = () => czDetail(+b.dataset.det));
  if ($("#rnew")) $("#rnew").onclick = czNouvelleResa;
}
function czNouvelleResa(){
  const L = CZ.ini.logements.filter(l => l.actif); const t = czIso(new Date());
  $("#view").insertAdjacentHTML("afterbegin", `<div class="card accent" id="nres"><h2>Réservation directe</h2>
    <label>Logement<select id="nl">${L.map(l => `<option value="${l.id}">${esc(l.nom)}</option>`).join("")}</select></label>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><label>Arrivée<input type="date" id="na" value="${t}"></label><label>Départ<input type="date" id="nd" value="${czAdd(t, 3)}"></label><label>Voyageurs adultes<input type="number" id="nn" value="2" min="1" max="30"></label></div>
    <label>Nom ou référence<input id="nlib" placeholder="Famille Martin, agence…"></label><div style="display:flex;gap:8px"><button class="primary" id="nok">Créer</button><button class="btn ghost" id="nko">Annuler</button></div></div>`);
  $("#nko").onclick = () => $("#nres").remove();
  $("#nok").onclick = async () => { try { const r = await czapi("reservation_save", {logement_id:+$("#nl").value, arrivee:$("#na").value, depart:$("#nd").value, nb:+$("#nn").value, libelle:$("#nlib").value}); toast("Réservation créée, la mission de ménage du départ aussi."); czDetail(r.id); } catch(e) { toast(e.message); } };
}
async function czDetail(id){ try { CZ.det = await czapi("reservation_get", null, "id=" + id); CZ.decl = null; G.sub = CZ.vu = "cz_reservations"; go("gestion"); } catch(e) { toast(e.message); } }
function czVDetail(){
  const D = CZ.det, r = D.reservation, fv = r.fv;
  const fiches = D.fiches.map((f, i) => `<tr><td>${i + 1}</td><td>${esc(f.resume)}</td><td>${esc(czD(f.recue))}</td><td>${f.scelle ? `<span class="pill">Sous scellé</span>` : `<span class="pill p-ok">Reçue</span>`}</td>
    <td>${f.piece ? (D.titulaire ? `<a class="btn" href="conciergerie_api.php?action=piece_voir&id=${f.id}" target="_blank" rel="noopener">Voir la pièce</a>` : `<span class="hint">Pièce au coffre</span>`) : `<span class="hint">Sans pièce</span>`}</td></tr>`).join("");
  return `<button class="btn ghost" id="dback">‹ Réservations</button>
   <div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><h2>${esc(r.logement)}</h2>${czFv(r.statut === "confirmee" ? fv : null)}</div>
    <div class="muted">${esc(czD(r.arrivee))} → ${esc(czD(r.depart))} · ${esc((CZ.ini.sources || {})[r.source] || r.source)}${r.libelle ? " · " + esc(r.libelle) : ""}</div>
    ${fv && !fv.ok ? `<div class="hint">Bloque : ${esc(fv.bloque.join(" · "))}</div>` : ""}
    ${r.heure ? `<div class="hint">${czArr(Object.assign({}, r, {heure_connue: !!r.heure}))}</div>` : ""}
    ${r.statut === "confirmee" ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">${czWaBloc(r.wa)}</div>` : ""}
    ${r.code_envoye ? `<div class="hint">Code d'accès envoyé le ${esc(czD(r.code_envoye))}.</div>` : ""}
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
      ${r.statut === "confirmee" ? `<button class="btn" id="dlien">Copier le lien voyageur</button>` : ""}
      ${CZ.ini.gerer && r.statut === "confirmee" ? `<label style="display:flex;gap:6px;align-items:center">Voyageurs adultes<input type="number" id="dnb" value="${r.nb}" min="1" max="30" style="width:80px"></label>` : ""}
      ${CZ.ini.gerer && r.statut === "confirmee" ? `<button class="primary" id="dcode">${fv.ok ? "Envoyer le code" : "Envoyer le code (feu vert requis)"}</button>` : ""}
      ${D.titulaire && r.statut === "confirmee" && !fv.ok ? `<button class="btn ghost" id="dforce">Passer outre le feu vert</button>` : ""}
      ${D.titulaire && D.fiches.length ? `<button class="btn" id="ddecl">Fiches pour la déclaration</button>` : ""}
      ${CZ.ini.gerer && r.statut === "confirmee" && r.source === "direct" ? `<button class="btn ghost" id="dann">Annuler la réservation</button>` : ""}</div></div>
   <div class="card"><h2>Fiches voyageurs</h2>${fiches ? `<div class="scroll"><table class="tbl"><thead><tr><th>#</th><th>Voyageur</th><th>Reçue</th><th>État</th><th>Pièce</th></tr></thead><tbody>${fiches}</tbody></table></div>` : `<div class="muted">Aucune fiche reçue. Envoyez le lien au voyageur.</div>`}
    <div class="hint">${D.titulaire ? "Vous voyez le contenu des fiches en tant que dirigeant(e). Chaque consultation de pièce est enregistrée, l'image est floutée et porte votre nom." : "Seul(e) un(e) dirigeant(e) peut lire le contenu des fiches. L'équipe voit uniquement leur état."}</div></div>
   ${r.statut === "confirmee" ? czVMessages(D) : ""}
   ${(D.commandes || []).length ? `<div class="card"><h2>Services commandés</h2><table class="tbl"><tbody>${D.commandes.map(c => `<tr><td>${c.quantite} × ${esc(c.nom)}</td><td>${czDH(c.quantite * c.prix)}</td><td><span class="hint">marge ${czDH(c.quantite * (c.prix - c.cout))}</span></td><td>${esc(c.statut)}</td></tr>`).join("")}</tbody></table></div>` : ""}
   ${(D.avis || []).length ? `<div class="card"><h2>Avis du voyageur</h2>${D.avis.map(v => `<div><b>${v.moment === "arrivee" ? "À l'arrivée" : "Après le séjour"}</b> · ${[["Propreté", v.proprete], ["Logement", v.logement], ["Accueil", v.accueil]].filter(x => x[1] != null).map(x => x[0] + " " + x[1] + "/5").join(" · ")}${v.commentaire ? `<div class="hint">« ${esc(v.commentaire)} »</div>` : ""}</div>`).join("")}</div>` : ""}
   ${CZ.decl ? czVDecl() : ""}`;
}
function czVDecl(){
  const D = CZ.decl, T = D.types || {};
  const rows = D.fiches.map((f, i) => `<tr><td>${i + 1}</td><td>${esc((f.nom || "").toUpperCase())} ${esc(f.prenom)}</td><td>${esc(f.sexe)}</td><td>${esc(f.date_naissance)}<div class="hint">${esc(f.lieu_naissance)}</div></td><td>${esc(f.nationalite)}</td><td>${esc(T[f.piece_type] || f.piece_type)}<div class="hint">${esc(f.piece_numero)}${f.piece_delivrance ? " · " + esc(f.piece_delivrance) : ""}</div></td><td>${esc(f.adresse)}<div class="hint">${esc(f.profession)}</div></td><td>${esc(f.venant_de)}<div class="hint">${esc(f.allant_a)}</div></td></tr>`).join("");
  return `<div class="card" id="decl"><h2>Déclaration des voyageurs</h2><div class="muted">${esc(D.reservation.logement)}, ${esc(D.reservation.ville)} · du ${esc(D.reservation.arrivee)} au ${esc(D.reservation.depart)}</div>
    <div class="scroll"><table class="tbl"><thead><tr><th>#</th><th>Nom, prénom</th><th>Sexe</th><th>Naissance</th><th>Nationalité</th><th>Pièce</th><th>Adresse, profession</th><th>Provenance, destination</th></tr></thead><tbody>${rows}</tbody></table></div>
    <div class="hint">À reporter sur la plateforme de télédéclaration ou à imprimer pour le commissariat. Cette consultation est enregistrée dans le journal.</div><button class="btn" id="dprint">Imprimer</button></div>`;
}
function czBDetail(){
  const r = CZ.det.reservation;
  $("#dback").onclick = () => { CZ.det = null; CZ.decl = null; czRe(); };
  if ($("#dlien")) $("#dlien").onclick = () => czCopier(r.lien, "Lien voyageur copié.");
  if ($("#dnb")) $("#dnb").onchange = async e => { try { await czapi("reservation_nb", {id:r.id, nb:+e.target.value}); czDetail(r.id); } catch(x) { toast(x.message); } };
  if ($("#dcode")) $("#dcode").onclick = async () => { await czCode(r.id); czDetail(r.id); };
  if ($("#dforce")) $("#dforce").onclick = async () => { if (!confirm("Envoyer le code alors que le feu vert n'est pas donné ? Cet envoi sera noté dans le journal.")) return; await czCode(r.id, true); czDetail(r.id); };
  if ($("#ddecl")) $("#ddecl").onclick = async () => { try { CZ.decl = await czapi("fiches_declaration", null, "id=" + r.id); go("gestion"); } catch(e) { toast(e.message); } };
  if ($("#dprint")) $("#dprint").onclick = () => window.print();
  czBMessages(r);
  if ($("#dann")) $("#dann").onclick = async () => { if (!confirm("Annuler cette réservation ? La mission de ménage prévue sera retirée.")) return; try { await czapi("reservation_annuler", {id:r.id}); CZ.det = null; czRe(); } catch(e) { toast(e.message); } };
}

/* ---------- Missions ---------- */
function czVMis(){
  if (CZ.mdet) return czVMisDet();
  const A = CZ.ini.agents, par = {}; (CZ.mis || []).forEach(m => (par[m.date] = par[m.date] || []).push(m));
  const jours = Object.keys(par).sort().map(d => `<div class="card"><h3>${esc(czD(d))}</h3><div class="scroll"><table class="tbl"><thead><tr><th>Heure</th><th>Logement</th><th>Type</th><th>Équipe</th><th>État</th><th></th></tr></thead><tbody>
    ${par[d].map(m => `<tr><td>${esc(m.heure)}</td><td>${esc(m.logement)}</td><td>${esc((CZ.ini.types || {})[m.type] || m.type)}</td>
      <td>${CZ.ini.gerer && m.statut !== "faite" ? `<select data-agent="${m.id}" data-date="${m.date}" data-heure="${esc(m.heure)}"><option value="">Non attribuée</option>${A.map(a => `<option value="${a.id}" ${+m.animatrice_id === +a.id ? "selected" : ""}>${esc(a.prenom + " " + (a.nom || ""))}</option>`).join("")}</select>` : esc(m.agent || "")}</td>
      <td>${czSt(m.statut)}</td><td style="white-space:nowrap">${CZ.ini.gerer && m.statut !== "faite" ? `<button class="btn" data-wal="${m.id}" title="Envoyer la mission à l'agent par WhatsApp">WhatsApp</button> ` : ""}<button class="btn ghost" data-mis="${m.id}">Voir</button></td></tr>`).join("")}</tbody></table></div></div>`).join("");
  return `<div class="card"><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end"><label>Du<input type="date" id="mf" value="${CZ.misF.from}"></label><label>Au<input type="date" id="mt" value="${CZ.misF.to}"></label><button class="btn" id="mgo">Afficher</button>
    ${CZ.ini.gerer ? `<button class="primary" id="mnew">Mission de contrôle ou de maintenance</button>` : ""}</div>
    ${A.length ? "" : `<div class="hint">Aucun membre d'équipe : ajoutez vos agents dans « Équipe » (ils se connectent à l'appli avec leur code).</div>`}</div>
    ${jours || `<div class="card muted">Aucune mission sur la période.</div>`}`;
}
function czBMis(){
  if (CZ.mdet) return czBMisDet();
  $("#mgo").onclick = () => { CZ.misF = {from:$("#mf").value, to:$("#mt").value}; czRe(); };
  document.querySelectorAll("[data-mis]").forEach(b => b.onclick = () => czMission(+b.dataset.mis));
  document.querySelectorAll("[data-wal]").forEach(b => b.onclick = () => czWaMission(+b.dataset.wal));
  document.querySelectorAll("[data-agent]").forEach(s => s.onchange = async () => { try { await czapi("mission_save", {id:+s.dataset.agent, animatrice_id:+s.value, date:s.dataset.date, heure:s.dataset.heure}); toast("Mission attribuée."); } catch(e) { toast(e.message); } });
  if ($("#mnew")) $("#mnew").onclick = () => {
    const t = czIso(new Date());
    $("#view").insertAdjacentHTML("afterbegin", `<div class="card accent" id="nm"><h2>Nouvelle mission</h2>
      <label>Logement<select id="ml">${CZ.ini.logements.filter(l => l.actif).map(l => `<option value="${l.id}">${esc(l.nom)}</option>`).join("")}</select></label>
      <div style="display:flex;gap:10px;flex-wrap:wrap"><label>Type<select id="mty"><option value="controle">Contrôle</option><option value="maintenance">Maintenance</option><option value="menage">Ménage</option></select></label><label>Date<input type="date" id="md" value="${t}"></label><label>Heure<input type="time" id="mh" value="10:00"></label></div>
      <label>Consigne<input id="mno" placeholder="Vérifier la climatisation, remplacer l'ampoule de l'entrée…"></label><div style="display:flex;gap:8px"><button class="primary" id="mok">Créer</button><button class="btn ghost" id="mko">Annuler</button></div></div>`);
    $("#mko").onclick = () => $("#nm").remove();
    $("#mok").onclick = async () => { try { await czapi("mission_save", {logement_id:+$("#ml").value, type:$("#mty").value, date:$("#md").value, heure:$("#mh").value, note:$("#mno").value}); toast("Mission créée."); czRe(); } catch(e) { toast(e.message); } };
  };
}
async function czMission(id){ try { CZ.mdet = (await czapi("mission_get", null, "id=" + id)).mission; G.sub = CZ.vu = "cz_missions"; go("gestion"); } catch(e) { toast(e.message); } }
function czVMisDet(){
  const m = CZ.mdet;
  return `<button class="btn ghost" id="mback">‹ Missions</button><div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><h2>${esc(m.logement)}</h2>${czSt(m.statut)}</div>
    <div class="muted">${esc((CZ.ini.types || {})[m.type] || m.type)} · ${esc(czD(m.date))} ${esc(m.heure)} · ${esc(m.agent || "non attribuée")}</div>${m.note ? `<div class="hint">${esc(m.note)}</div>` : ""}
    ${m.debut ? `<div class="hint">Arrivée ${esc(String(m.debut).slice(11, 16))}${m.debut_pos ? " · " + esc(m.debut_pos) : ""}${m.fin ? ` · départ ${esc(String(m.fin).slice(11, 16))}${m.fin_pos ? " · " + esc(m.fin_pos) : ""}` : ""}${m.duree_reelle != null ? ` · ${m.duree_reelle} min sur place (prévu ${m.duree_prevue})` : ""}</div>` : ""}
    ${m.loin ? `<div><span class="pill p-warn">Position éloignée du logement</span> <span class="hint">À vérifier avec l'agent : le téléphone peut aussi manquer de précision.</span></div>` : ""}
    ${m.manques.length && m.statut !== "faite" ? `<div class="hint">Reste à faire : ${esc(m.manques.join(" · "))}</div>` : ""}
    ${CZ.ini.gerer && m.statut !== "faite" ? `<button class="btn" id="mwa">Envoyer à l'agent (WhatsApp)</button>` : ""}${CZ.ini.gerer && m.statut === "faite" ? `<button class="btn ghost" id="mrep">Demander de reprendre</button>` : ""}${CZ.ini.gerer && m.statut === "faite" && czPropDe(m.logement_id) ? ` <button class="btn" id="mprop">Envoyer au propriétaire</button>` : ""}</div>
    ${m.pieces.map(p => `<div class="card"><div style="display:flex;justify-content:space-between"><b>${esc(p.piece)}</b>${p.ok ? `<span class="pill p-ok">Validée</span>` : `<span class="pill">À faire</span>`}</div>${p.consignes ? `<div class="hint">${esc(p.consignes)}</div>` : ""}
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">${p.urls.map((u, i) => `<a href="${esc(p.grandes[i])}" target="_blank" rel="noopener"><img src="${esc(u)}" alt="Photo ${esc(p.piece)}" style="width:110px;height:110px;object-fit:cover"></a>`).join("") || `<span class="hint">Aucune photo (${p.photos} demandée(s))</span>`}</div></div>`).join("")}`;
}
function czBMisDet(){
  $("#mback").onclick = () => { CZ.mdet = null; czRe(); };
  if ($("#mwa")) $("#mwa").onclick = () => czWaMission(CZ.mdet.id);
  if ($("#mprop")) $("#mprop").onclick = async () => { const n = prompt("Un mot pour le propriétaire (facultatif) :", "Intervention terminée, tout est en ordre."); if (n === null) return;
    try { const r = await czapi("prop_rapport_envoyer", {id:czPropDe(CZ.mdet.logement_id), type:"visite", mission_id:CZ.mdet.id, note:n}); czNotif(r, "Rapport de visite envoyé au propriétaire"); } catch(e) { toast(e.message); } };
  if ($("#mrep")) $("#mrep").onclick = async () => { const n = prompt("Ce qui doit être repris :", ""); if (n === null) return; try { await czapi("mission_reprendre", {id:CZ.mdet.id, note:n}); czMission(CZ.mdet.id); } catch(e) { toast(e.message); } };
}

/* ---------- Logements ---------- */
function czVLogements(){
  if (CZ.wiz) return czVWiz();
  if (CZ.ed) return czVLogEd();
  const P = Object.fromEntries(CZ.ini.proprietaires.map(p => [p.id, p.nom])), R = CZ.ini.reglages;
  return `<div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><h2>Logements</h2>${CZ.ini.gerer ? `<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn ghost" id="lnewf">Formulaire complet</button><button class="primary" id="lnew">Nouveau logement</button></div>` : ""}</div>
    ${CZ.ini.logements.length ? `<div class="scroll"><table class="tbl"><thead><tr><th>Logement</th><th>Ville</th><th>Propriétaire</th><th>Calendriers</th><th></th></tr></thead><tbody>
    ${CZ.ini.logements.map(l => `<tr${l.actif ? "" : ` style="opacity:.55"`}><td>${esc(l.nom)}</td><td>${esc(l.ville)}</td><td>${esc(P[l.proprietaire_id] || "")}</td><td>${l.ical.length}${l.ical_maj ? `<div class="hint">synchronisé ${esc(czD(l.ical_maj))}</div>` : ""}</td>
      <td style="white-space:nowrap">${CZ.ini.gerer ? `<button class="btn" data-led="${l.id}">Modifier</button> ${l.ical.length ? `<button class="btn ghost" data-sync="${l.id}">Synchroniser</button>` : ""}` : ""}</td></tr>`).join("")}</tbody></table></div>` : `<div class="muted">Ajoutez votre premier logement : adresse, accès, check-list du ménage et liens des calendriers Airbnb ou Booking.</div>`}</div>
    ${CZ.ini.titulaire ? `<div class="card"><h2>Réglages de la conciergerie</h2>
      <label>Conservation des fiches voyageurs (jours)<input type="number" id="rcj" min="30" max="3650" value="${R.cz_conservation_jours}"></label><div class="hint">Délai légal à faire valider par votre conseil. Après le départ, les fiches passent sous scellé ; à la fin de ce délai, elles sont effacées.</div>
      <label class="chk"><input type="checkbox" id="rcp" ${R.cz_copie_piece === "1" ? "checked" : ""}> Demander la photo de la pièce d'identité au voyageur</label>
      <label class="chk"><input type="checkbox" id="rca" ${R.cz_code_auto === "1" ? "checked" : ""}> Envoyer le code d'accès automatiquement dès le feu vert</label>
      <label class="chk"><input type="checkbox" id="ria" ${R.cz_ia === "1" ? "checked" : ""}> Assistant pour les voyageurs : il répond aux questions sur leur page et prépare vos réponses WhatsApp</label>${R.ia_dispo ? "" : `<div class="hint">Assistant pas encore branché sur cette installation.</div>`}
      <label>Heure habituelle du ménage<input type="time" id="rch" value="${esc(R.cz_heure_menage)}"></label><button class="btn" id="rsave">Enregistrer les réglages</button></div>` : ""}`;
}
function czBLogements(){
  if (CZ.wiz) return czBWiz();
  if (CZ.ed) return czBLogEd();
  if ($("#lnew")) $("#lnew").onclick = () => { CZ.wiz = {etape:1, d:{ville:"Marrakech", type:"Appartement 2 chambres", entretiens:true}}; czRe(); };
  if ($("#lnewf")) $("#lnewf").onclick = () => { CZ.ed = {id:0, nom:"", ville:"", adresse:"", proprietaire_id:null, agent_id:null, acces:"", code:"", kit_linge:"", duree_menage:120, ical:[], notes:"", actif:1, checklist:[{piece:"Chambre",photos:2},{piece:"Salle de bain",photos:2},{piece:"Cuisine",photos:2},{piece:"Salon",photos:2}]}; czRe(); };
  document.querySelectorAll("[data-led]").forEach(b => b.onclick = async () => { try { CZ.ed = (await czapi("logement_get", null, "id=" + b.dataset.led)).logement; czRe(); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-sync]").forEach(b => b.onclick = async () => { b.disabled = true; try { const r = await czapi("logement_sync", {id:+b.dataset.sync}); toast(`${r.ajoutees} nouvelle(s), ${r.modifiees} modifiée(s), ${r.annulees} annulée(s).`); CZ.ini = null; czRe(); } catch(e) { toast(e.message); b.disabled = false; } });
  if ($("#rsave")) $("#rsave").onclick = async () => { try { await czapi("reglages_save", {cz_conservation_jours:+$("#rcj").value, cz_copie_piece:$("#rcp").checked, cz_code_auto:$("#rca").checked, cz_heure_menage:$("#rch").value, cz_ia:$("#ria").checked}); CZ.ini = null; toast("Réglages enregistrés."); } catch(e) { toast(e.message); } };
}
function czVLogEd(){
  const l = CZ.ed, opt = (L, v, f) => L.map(x => `<option value="${x.id}" ${+v === +x.id ? "selected" : ""}>${esc(f(x))}</option>`).join("");
  return `<button class="btn ghost" id="lback">‹ Logements</button><div class="card"><h2>${l.id ? esc(l.nom) : "Nouveau logement"}</h2>
    <label>Nom<input id="ln" value="${esc(l.nom)}" placeholder="Villa Palmeraie, Riad Zitoun…"></label>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><label style="flex:1">Ville<input id="lv" value="${esc(l.ville)}"></label><label style="flex:2">Adresse<input id="la" value="${esc(l.adresse)}"></label></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><label>Propriétaire<select id="lp"><option value="">Aucun</option>${opt(CZ.ini.proprietaires, l.proprietaire_id, p => p.nom)}</select></label>
      <label>Agent habituel<select id="lag"><option value="">Attribution automatique</option>${opt(CZ.ini.agents, l.agent_id, a => a.prenom + " " + (a.nom || ""))}</select></label>
      <label>Kit de linge<input id="lk" value="${esc(l.kit_linge)}" placeholder="2 lits doubles, 4 serviettes…"></label><label>Durée du ménage (min)<input type="number" id="ld" value="${l.duree_menage}" min="15" max="600"></label></div>
    <label>Consignes d'accès <span class="hint">· chiffrées, visibles par l'agent le jour de sa mission et envoyées au voyageur avec le code</span><textarea id="lacc" rows="3">${esc(l.acces || "")}</textarea></label>
    <label>Code d'accès (boîte à clés, serrure) <span class="hint">· chiffré</span><input id="lc" value="${esc(l.code || "")}" autocomplete="off"></label>
    ${czVLivret(l)}
    <label>Calendriers iCal <span class="hint">· un lien par ligne : Airbnb (Annonce › Disponibilités › Exporter le calendrier), Booking (Tarifs et disponibilités › Synchroniser les calendriers)</span><textarea id="li" rows="3" placeholder="https://www.airbnb.fr/calendar/ical/…">${esc(l.ical.join("\n"))}</textarea></label>
    <h3>Check-list du ménage</h3><div class="hint">Partir d'un modèle : ${Object.keys(CZ_MODELES_LOG).map(k => `<button type="button" class="btn ghost" data-lmod="${esc(k)}" style="padding:6px 10px">${esc(k)}</button>`).join(" ")} <span>puis ajustez.</span></div><div id="lchk">${l.checklist.map((p, i) => czChkRow(p, i)).join("")}</div><button class="btn ghost" id="laddp">Ajouter une pièce</button>
    <label>Notes internes<textarea id="lno" rows="2">${esc(l.notes || "")}</textarea></label>
    ${l.id ? `<label class="chk"><input type="checkbox" id="lact" ${l.actif ? "checked" : ""}> Logement actif</label>` : ""}
    <button class="primary" id="lsave">Enregistrer</button></div>`;
}
const czChkRow = (p, i) => `<div class="chkrow" style="display:grid;grid-template-columns:2fr 3fr 90px 40px;gap:8px;margin-bottom:6px"><input data-cp="${i}" value="${esc(p.piece)}" placeholder="Pièce"><input data-cc="${i}" value="${esc(p.consignes || "")}" placeholder="Consignes (facultatif)"><input data-cn="${i}" type="number" min="0" max="10" value="${p.photos}" title="Photos demandées"><button class="btn ghost" data-cdel="${i}" title="Retirer">×</button></div>`;
function czLireChk(){ return [...document.querySelectorAll("[data-cp]")].map(x => { const i = x.dataset.cp; return {piece:x.value.trim(), consignes:$(`[data-cc="${i}"]`).value.trim(), photos:+$(`[data-cn="${i}"]`).value}; }).filter(p => p.piece); }
function czBLogEd(){
  $("#lback").onclick = () => { CZ.ed = null; czRe(); };
  document.querySelectorAll("[data-lmod]").forEach(b => b.onclick = () => { if (CZ.ed.id && !confirm("Remplacer la check-list actuelle par le modèle « " + b.dataset.lmod + " » ?")) return;
    CZ.ed.checklist = CZ_MODELES_LOG[b.dataset.lmod].map(([piece, photos, consignes]) => ({piece, photos, consignes:consignes || ""})); $("#lchk").innerHTML = CZ.ed.checklist.map((p, i) => czChkRow(p, i)).join(""); czBDel(); });
  $("#laddp").onclick = () => { CZ.ed.checklist = czLireChk(); CZ.ed.checklist.push({piece:"", photos:1}); $("#lchk").innerHTML = CZ.ed.checklist.map((p, i) => czChkRow(p, i)).join(""); czBDel(); };
  const czBDel = () => document.querySelectorAll("[data-cdel]").forEach(b => b.onclick = () => { const L = czLireChk(); L.splice(+b.dataset.cdel, 1); CZ.ed.checklist = L; $("#lchk").innerHTML = L.map((p, i) => czChkRow(p, i)).join(""); czBDel(); });
  czBDel(); czBLivret();
  $("#lsave").onclick = async () => {
    const l = CZ.ed, b = $("#lsave"); b.disabled = true;
    try { const r = await czapi("logement_save", {id:l.id, nom:$("#ln").value, ville:$("#lv").value, adresse:$("#la").value, proprietaire_id:+$("#lp").value, agent_id:+$("#lag").value, kit_linge:$("#lk").value, duree_menage:+$("#ld").value,
        acces:$("#lacc").value, code:$("#lc").value, position: l._pos || $("#lpos").value, livret: czLireLivret(), ical:$("#li").value, checklist:czLireChk(), notes:$("#lno").value, actif: $("#lact") ? $("#lact").checked : true});
      toast(r.sync == null ? "Logement enregistré." : Array.isArray(r.sync) ? `Logement enregistré. Calendriers : ${r.sync[0]} réservation(s) importée(s).` : "Logement enregistré. Calendrier : " + r.sync);
      CZ.ed = null; CZ.ini = null; czRe();
    } catch(e) { toast(e.message); b.disabled = false; }
  };
}

/* ---------- Signalements ---------- */
function czVInc(){
  return `<div class="card"><div style="display:flex;gap:8px"><button class="btn${CZ.incS === "ouvert" ? " primary" : ""}" data-is="ouvert">Ouverts</button><button class="btn${CZ.incS === "clos" ? " primary" : ""}" data-is="clos">Clos</button></div></div>
   ${(CZ.inc || []).map(i => `<div class="card" style="display:grid;gap:8px"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><b>${esc(i.logement)}</b><span class="hint">${esc(czD(i.created_at))} · ${esc(i.auteur)}</span></div>
     <div>${esc(i.description)}</div>${i.photo_url ? `<img src="${esc(i.photo_url)}" alt="Photo du problème" style="max-width:220px">` : ""}
     ${i.proprio_statut ? `<div class="hint">Propriétaire : ${i.proprio_statut === "demande" ? "validation demandée" : i.proprio_statut === "accepte" ? "a accepté la dépense" : "a refusé la dépense"}</div>` : ""}
     ${CZ.ini.gerer ? `<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end"><label class="chk"><input type="checkbox" data-ib="${i.id}" ${+i.bloquant ? "checked" : ""}> Bloque l'arrivée suivante</label>
       <label>Coût<input data-ic="${i.id}" value="${i.cout ?? ""}" inputmode="decimal" style="width:110px"></label>
       <label class="chk"><input type="checkbox" data-iv="${i.id}" ${i.proprio_statut ? "checked disabled" : ""}> Demander l'accord du propriétaire</label>
       <button class="btn" data-isave="${i.id}">Enregistrer</button>${i.statut === "ouvert" ? `<button class="primary" data-iclos="${i.id}">Clore</button>` : ""}</div>` : ""}</div>`).join("") || `<div class="card muted">Aucun signalement.</div>`}`;
}
function czBInc(){
  document.querySelectorAll("[data-is]").forEach(b => b.onclick = () => { CZ.incS = b.dataset.is; czRe(); });
  const maj = async (id, clos) => { try { await czapi("incident_maj", {id, bloquant:$(`[data-ib="${id}"]`).checked, cout:$(`[data-ic="${id}"]`).value, valider_proprio:$(`[data-iv="${id}"]`).checked, statut: clos ? "clos" : (CZ.incS === "clos" ? "clos" : "ouvert")}); toast("Enregistré."); czRe(); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-isave]").forEach(b => b.onclick = () => maj(+b.dataset.isave, false));
  document.querySelectorAll("[data-iclos]").forEach(b => b.onclick = () => maj(+b.dataset.iclos, true));
}

/* ---------- Propriétaires ---------- */
function czVProp(){
  if (CZ.pfId && CZ.pf) return czVPf();
  const P = CZ.ini.proprietaires, n = id => CZ.ini.logements.filter(l => +l.proprietaire_id === +id).length;
  return `${CZ.lien ? `<div class="card accent"><h2>Lien de ${esc(CZ.lien.nom)}</h2><div class="muted">Affiché une seule fois. Transmettez-le au propriétaire : il ouvre son espace sans mot de passe. Un nouveau lien annule le précédent.</div>
      <input readonly value="${esc(CZ.lien.url)}" id="plien" style="width:100%"><button class="primary" id="pcop">Copier le lien</button></div>` : ""}
    <div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><h2>Propriétaires</h2>${CZ.ini.gerer ? `<button class="primary" id="pnew">Nouveau propriétaire</button>` : ""}</div>
    ${P.length ? `<div class="scroll"><table class="tbl"><thead><tr><th>Nom</th><th>Contact</th><th>Logements</th><th>Espace</th><th></th></tr></thead><tbody>
    ${P.map(p => `<tr><td>${esc(p.nom)}</td><td>${esc(p.email)}<div class="hint">${esc(p.telephone)}</div></td><td>${n(p.id)}</td><td>${+p.lien ? `<span class="pill p-ok">Lien actif</span>` : `<span class="pill">Pas de lien</span>`}</td>
      <td style="white-space:nowrap"><button class="primary" data-pfo="${p.id}">Ouvrir</button> ${CZ.ini.gerer ? `<button class="btn" data-ped="${p.id}">Modifier</button> <button class="btn ghost" data-plink="${p.id}">${+p.lien ? "Nouveau lien" : "Créer le lien"}</button>` : ""}</td></tr>`).join("")}</tbody></table></div>` : `<div class="muted">Aucun propriétaire. Créez-le, rattachez-lui ses logements, puis envoyez-lui le lien de son espace.</div>`}</div>`;
}
function czBProp(){
  if (CZ.pfId && CZ.pf) return czBPf();
  document.querySelectorAll("[data-pfo]").forEach(b => b.onclick = () => { CZ.pfId = +b.dataset.pfo; CZ.pfRap = null; czRe(); });
  if ($("#pcop")) $("#pcop").onclick = () => czCopier(CZ.lien.url, "Lien copié.");
  const form = p => {
    $("#view").insertAdjacentHTML("afterbegin", `<div class="card accent" id="pf"><h2>${p.id ? "Modifier" : "Nouveau propriétaire"}</h2><label>Nom<input id="pn" value="${esc(p.nom || "")}"></label>
      <div style="display:flex;gap:10px;flex-wrap:wrap"><label>E-mail<input id="pe" type="email" value="${esc(p.email || "")}"></label><label>Téléphone<input id="pt" value="${esc(p.telephone || "")}"></label><label>Plafond de dépenses sans accord<input id="ppl" value="${p.plafond || 0}" inputmode="decimal"></label></div>
      ${p.id ? `<label class="chk"><input type="checkbox" id="pa" ${+p.actif ? "checked" : ""}> Actif</label>` : ""}<div style="display:flex;gap:8px"><button class="primary" id="pok">Enregistrer</button><button class="btn ghost" id="pko">Annuler</button></div></div>`);
    $("#pko").onclick = () => $("#pf").remove();
    $("#pok").onclick = async () => { try { await czapi("proprietaire_save", {id:p.id || 0, nom:$("#pn").value, email:$("#pe").value, telephone:$("#pt").value, plafond:$("#ppl").value, actif: $("#pa") ? $("#pa").checked : true}); CZ.ini = null; toast("Enregistré."); czRe(); } catch(e) { toast(e.message); } };
  };
  if ($("#pnew")) $("#pnew").onclick = () => form({});
  document.querySelectorAll("[data-ped]").forEach(b => b.onclick = () => form(CZ.ini.proprietaires.find(p => +p.id === +b.dataset.ped)));
  document.querySelectorAll("[data-plink]").forEach(b => b.onclick = async () => { const p = CZ.ini.proprietaires.find(x => +x.id === +b.dataset.plink);
    if (+p.lien && !confirm("Créer un nouveau lien ? L'ancien ne fonctionnera plus.")) return;
    try { const r = await czapi("proprietaire_lien", {id:p.id}); CZ.lien = {nom:p.nom, url:r.lien}; CZ.ini = null; czRe(); } catch(e) { toast(e.message); } });
}

/* ---------- Mission envoyée par WhatsApp ---------- */
async function czWaMission(id){
  try { const r = await czapi("mission_lien", {id});
    if (r.wa) window.open(r.wa, "_blank", "noopener");
    else czCopier(r.texte, "Pas de téléphone pour cet agent : le message de mission est copié, collez-le dans WhatsApp.");
  } catch(e) { toast(e.message); }
}

/* ---------- Planning du gestionnaire ---------- */
const czH = n => String(Math.floor(n / 60)).padStart(2, "0") + ":" + String(n % 60).padStart(2, "0");
function czVPlan(){
  const P = CZ.plan, A = CZ.ini.agents, G2 = CZ.ini.admins || [], DEB = 7 * 60, FIN = 22 * 60, W = FIN - DEB;
  const lignes = [...A.map(a => ({k:"a" + a.id, nom:a.prenom + " " + (a.nom || ""), m:P.missions.filter(m => m.agent === +a.id)})), ...G2.map(g => ({k:"g" + g.id, nom:g.nom + " (gestion)", m:P.missions.filter(m => m.admin === +g.id)}))];
  const libre = P.missions.filter(m => !m.agent && !m.admin);
  if (libre.length) lignes.push({k:"", nom:"Non attribuées", m:libre});
  const pct = n => ((Math.max(DEB, Math.min(FIN, n)) - DEB) / W * 100).toFixed(2) + "%";
  const bloc = m => `<button class="pl-b pl-${esc(m.type)} ${m.statut === "faite" ? "pl-ok" : ""}" data-mis="${m.id}" style="left:${pct(m.debut)};width:calc(${pct(m.fin)} - ${pct(m.debut)})" title="${esc(m.heure + " · " + m.logement + " · " + ((CZ.ini.types || {})[m.type] || m.type))}">${esc(m.heure)} ${esc(m.logement)}</button>`;
  const regle = Array.from({length:16}, (_, i) => `<span style="left:${pct(DEB + i * 60)}">${String(7 + i).padStart(2, "0")}h</span>`).join("");
  const arr = P.arrivees.map(r => `<span class="pl-arr" style="left:${pct(r.minute)}" title="${esc(r.logement)}">▼ ${esc(r.heure)} ${esc(r.logement)}${r.vol ? " · " + esc(r.vol) : ""}${r.prevue ? " (heure par défaut)" : ""}</span>`).join("");
  return `<style>.pl{position:relative;border-top:1px solid var(--line,#e2e2e2)}.pl-r{position:relative;height:30px;margin:0 0 4px 160px}.pl-n{position:absolute;left:-160px;width:150px;top:6px;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .pl-g{position:relative;height:22px;margin-left:160px;font-size:.72rem;color:#888}.pl-g span{position:absolute;transform:translateX(-50%)}.pl-b{position:absolute;top:2px;height:26px;border:1px solid var(--line);border-radius:8px;background:#fff;font-size:.74rem;padding:0 4px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;cursor:pointer;text-align:left}
  .pl-menage{background:var(--vert);color:#fff;border-color:var(--vert)}.pl-maintenance,.pl-controle{background:var(--laiton-clair);color:#6E5220;border-color:#E6D3AE}.pl-accueil,.pl-transfert{background:#E4E8EB;border-color:#CBD2D7}.pl-ok{opacity:.45}.pl-a{position:relative;height:auto;min-height:24px;margin-left:160px}.pl-arr{position:absolute;top:0;font-size:.74rem;white-space:nowrap;color:#7a5a12}
  .pl-wrap{overflow-x:auto}.pl-in{min-width:900px}</style>
  <div class="card"><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end"><button class="btn" id="pp">‹</button><label>Jour<input type="date" id="pd" value="${CZ.planD}"></label><button class="btn" id="pn">›</button>
    ${CZ.ini.gerer ? `<button class="primary" id="pt">Nouvelle tâche</button>` : ""}</div>
    ${P.conflits.length ? `<div style="margin-top:10px;display:grid;gap:4px">${P.conflits.map(c => `<div><span class="pill ${c.niveau === "bad" ? "p-bad" : "p-warn"}">${c.niveau === "bad" ? "Conflit" : "À attribuer"}</span> ${esc(c.texte)}</div>`).join("")}</div>` : `<div class="hint" style="margin-top:8px">Aucun conflit ce jour-là.</div>`}</div>
  <div class="card"><h2>${esc(czD(CZ.planD))}</h2><div class="pl-wrap"><div class="pl-in"><div class="pl-g">${regle}</div>
    ${P.arrivees.length ? `<div class="pl-a"><span class="pl-n" style="left:-160px">Arrivées</span>${arr}</div>` : ""}
    ${lignes.map(l => `<div class="pl-r"><span class="pl-n">${esc(l.nom)}</span>${l.m.map(bloc).join("")}</div>`).join("") || `<div class="muted">Aucune personne dans l'équipe.</div>`}</div></div>
    ${P.departs.length ? `<div class="hint">Départs : ${P.departs.map(d => esc(d.logement + " (" + d.heure + ")")).join(", ")}</div>` : ""}</div>`;
}
function czBPlan(){
  const aller = d => { CZ.planD = d; czRe(); };
  $("#pd").onchange = e => aller(e.target.value); $("#pp").onclick = () => aller(czAdd(CZ.planD, -1)); $("#pn").onclick = () => aller(czAdd(CZ.planD, 1));
  document.querySelectorAll("[data-mis]").forEach(b => b.onclick = () => czMission(+b.dataset.mis));
  if ($("#pt")) $("#pt").onclick = () => {
    const T = CZ.ini.types || {}, A = CZ.ini.agents, G2 = CZ.ini.admins || [];
    $("#view").insertAdjacentHTML("afterbegin", `<div class="card accent" id="nt"><h2>Nouvelle tâche</h2>
      <label>Logement<select id="tl">${CZ.ini.logements.filter(l => l.actif).map(l => `<option value="${l.id}">${esc(l.nom)}</option>`).join("")}</select></label>
      <div style="display:flex;gap:10px;flex-wrap:wrap"><label>Type<select id="tty">${Object.entries(T).map(([k, v]) => `<option value="${k}" ${k === "accueil" ? "selected" : ""}>${esc(v)}</option>`).join("")}</select></label>
        <label>Date<input type="date" id="td" value="${CZ.planD}"></label><label>Heure<input type="time" id="th" value="15:00"></label><label>Durée (min)<input type="number" id="tdu" value="60" min="15" max="600" step="15" style="width:90px"></label></div>
      <label>Qui<select id="tq"><option value="">Non attribuée</option><optgroup label="Équipe terrain">${A.map(a => `<option value="a${a.id}">${esc(a.prenom + " " + (a.nom || ""))}</option>`).join("")}</optgroup><optgroup label="Gestion">${G2.map(g => `<option value="g${g.id}">${esc(g.nom)}</option>`).join("")}</optgroup></select></label>
      <label>Note<input id="tno" placeholder="Accueillir M. et Mme Benali, remettre les clés, présenter la terrasse…"></label>
      <div style="display:flex;gap:8px"><button class="primary" id="tok">Créer</button><button class="btn ghost" id="tko">Annuler</button></div></div>`);
    $("#tko").onclick = () => $("#nt").remove();
    $("#tok").onclick = async () => { try { await czapi("tache_save", {logement_id:+$("#tl").value, type:$("#tty").value, date:$("#td").value, heure:$("#th").value, duree:+$("#tdu").value, qui:$("#tq").value, note:$("#tno").value}); toast("Tâche ajoutée au planning."); CZ.planD = $("#td").value; czRe(); } catch(e) { toast(e.message); } };
  };
}

/* ---------- Avis des voyageurs ---------- */
const czEt = n => n == null ? "" : `<span title="${n}/5">${"★".repeat(Math.round(n))}<span style="opacity:.25">${"★".repeat(5 - Math.round(n))}</span></span>`;
function czVAvis(){
  const V = CZ.avis, moy = (L, titre) => `<div class="card"><h2>${titre}</h2>${L.length ? `<table class="tbl"><tbody>${L.map(x => `<tr><td>${esc(x.nom)}</td><td>${czEt(x.note)} ${x.note}/5</td><td class="hint">${x.n} avis</td></tr>`).join("")}</tbody></table>` : `<div class="muted">Pas encore d'avis.</div>`}</div>`;
  return `<div class="hint" style="margin-bottom:8px">Note de propreté donnée par le voyageur environ une heure après son arrivée, puis note du séjour après le départ. Une note de 3 ou moins ouvre un signalement.</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px">${moy(V.agents, "Propreté par agent")}${moy(V.logements, "Propreté par logement")}</div>
    <div class="card"><h2>Derniers avis</h2>${V.avis.length ? `<div class="scroll"><table class="tbl"><thead><tr><th>Date</th><th>Logement</th><th>Moment</th><th>Notes</th><th>Commentaire</th><th>Ménage par</th></tr></thead><tbody>
      ${V.avis.map(v => `<tr><td>${esc(czD(v.created_at))}</td><td>${esc(v.logement)}</td><td>${v.moment === "arrivee" ? "Arrivée" : "Séjour"}</td>
        <td style="white-space:nowrap">${v.proprete != null ? "Propreté " + czEt(v.proprete) : ""}${v.moment === "sejour" ? `Logement ${czEt(+v.note_logement)}<br>Accueil ${czEt(+v.accueil)}` : ""}</td>
        <td>${esc(v.commentaire || "")}${v.photo_url ? `<div><img src="${esc(v.photo_url)}" alt="Photo du voyageur" style="width:70px;height:70px;object-fit:cover;margin-top:4px"></div>` : ""}</td><td>${esc(v.agent || "")}</td></tr>`).join("")}</tbody></table></div>` : `<div class="muted">Aucun avis sur les 90 derniers jours.</div>`}</div>`;
}
function czBAvis(){}

/* ---------- Services à l'arrivée ---------- */
function czVSrv(){
  const S = CZ.srv, M = S.marges, e = CZ.srvEd;
  const form = e ? `<div class="card accent" id="se"><h2>${e.id ? "Modifier le service" : "Nouveau service"}</h2>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px"><label>Nom (français)<input id="sn" value="${esc(e.nom || "")}" placeholder="Corbeille de fruits de saison"></label><label>Nom (anglais)<input id="sne" value="${esc(e.nom_en || "")}"></label><label>Nom (darija)<input id="sna" dir="rtl" value="${esc(e.nom_ar || "")}"></label></div>
      <label>Description<input id="sd" value="${esc(e.description || "")}" placeholder="Fruits du marché, posés sur la table à votre arrivée"></label>
      <div style="display:flex;gap:10px;flex-wrap:wrap"><label>Prix voyageur (DH)<input id="sp" type="number" min="0" step="1" value="${e.prix ?? ""}" style="width:130px"></label><label>Votre coût (DH)<input id="sc" type="number" min="0" step="1" value="${e.cout ?? ""}" style="width:130px"></label>
        <label>Commander au moins (heures avant)<input id="sh" type="number" min="0" max="240" value="${e.delai_h ?? 24}" style="width:110px"></label><label>Ordre<input id="so" type="number" value="${e.ordre ?? 0}" style="width:80px"></label>
        ${e.id ? `<label style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="sa" ${+e.actif ? "checked" : ""}> Proposé aux voyageurs</label>` : ""}</div>
      <div class="hint" id="smg"></div><div style="display:flex;gap:8px"><button class="primary" id="sok">Enregistrer</button><button class="btn ghost" id="sko">Annuler</button></div></div>` : "";
  return `${form}<div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h2>Le menu « À votre arrivée »</h2>${CZ.ini.gerer && !e ? `<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn" id="scat">Choisir dans le catalogue</button><button class="primary" id="snew">Ajouter un service</button></div>` : ""}</div>${CZ.srvCat ? czVCat() : ""}
    <div class="hint">Le voyageur commande depuis son lien, avant d'arriver. Vous fixez le prix, vous notez votre coût réel : la différence est votre marge. Le règlement passe par vous (sur place ou avec la réservation), jamais par ${esc(typeof PRODUIT !== "undefined" && PRODUIT.nom ? PRODUIT.nom : "le logiciel")}.</div>
    ${S.services.length ? `<div class="scroll"><table class="tbl"><thead><tr><th>Service</th><th>Prix</th><th>Coût</th><th>Marge</th><th>Délai</th><th></th></tr></thead><tbody>${S.services.map(s => `<tr style="${+s.actif ? "" : "opacity:.5"}"><td><b>${esc(s.nom)}</b><div class="hint">${esc(s.description || "")}</div></td><td>${czDH(s.prix)}</td><td>${czDH(s.cout)}</td><td><b>${czDH(s.prix - s.cout)}</b>${+s.prix ? `<div class="hint">${Math.round((s.prix - s.cout) / s.prix * 100)} %</div>` : ""}</td><td>${s.delai_h} h</td><td>${CZ.ini.gerer ? `<button class="btn ghost" data-se="${s.id}">Modifier</button>` : ""}</td></tr>`).join("")}</tbody></table></div>` : `<div class="muted">Aucun service. Commencez par l'eau, les fruits, quelques courses et le transfert depuis l'aéroport.</div>`}</div>
    <div class="card"><h2>Ce que rapportent les services</h2><div class="hint">Du ${esc(czD(S.from))} au ${esc(czD(S.to))}</div>
      <div style="display:flex;gap:28px;flex-wrap:wrap;margin:10px 0"><div><div class="hint">Encaissé</div><b style="font-size:1.4rem">${czDH(M.ca)}</b></div><div><div class="hint">Coût</div><b style="font-size:1.4rem">${czDH(M.cout)}</b></div><div><div class="hint">Marge</div><b style="font-size:1.4rem">${czDH(M.marge)}</b></div></div>
      ${M.lignes.length ? `<table class="tbl"><thead><tr><th>Service</th><th>Quantité</th><th>Encaissé</th><th>Marge</th></tr></thead><tbody>${M.lignes.map(l => `<tr><td>${esc(l.nom)}</td><td>${+l.n}</td><td>${czDH(l.ca)}</td><td>${czDH(l.marge)}</td></tr>`).join("")}</tbody></table>` : `<div class="muted">Aucune commande ce mois-ci.</div>`}</div>`;
}
function czBSrv(){
  if ($("#scat")) $("#scat").onclick = () => { CZ.srvCat = !CZ.srvCat; czRe(); };
  if ($("#catx")) $("#catx").onclick = () => { CZ.srvCat = false; czRe(); };
  if ($("#catok")) $("#catok").onclick = async () => { const L = [...document.querySelectorAll("[data-cat]:checked")]; if (!L.length) return toast("Cochez au moins un service.");
    try { for (const c of L) { const i = +c.dataset.cat, x = CZ_CATALOGUE[i]; await czapi("service_save", {nom:x[0], nom_en:x[1], nom_ar:x[2], description:x[3], prix:$(`[data-catp="${i}"]`).value, cout:$(`[data-catc="${i}"]`).value, delai_h:x[6], ordre:CZ.srv.services.length + i}); }
      CZ.srvCat = false; toast(L.length + " service(s) ajouté(s) : vérifiez les prix."); czRe(); } catch(e) { toast(e.message); } };
  if ($("#snew")) $("#snew").onclick = () => { CZ.srvEd = {delai_h:24, ordre:CZ.srv.services.length}; czRe(); };
  document.querySelectorAll("[data-se]").forEach(b => b.onclick = () => { CZ.srvEd = Object.assign({}, CZ.srv.services.find(s => +s.id === +b.dataset.se)); czRe(); });
  if (!$("#se")) return;
  const mg = () => { const p = +$("#sp").value || 0, c = +$("#sc").value || 0; $("#smg").textContent = p ? `Marge : ${czDH(p - c)} par commande (${Math.round((p - c) / p * 100)} %)` : ""; };
  $("#sp").oninput = $("#sc").oninput = mg; mg();
  $("#sko").onclick = () => { CZ.srvEd = null; czRe(); };
  $("#sok").onclick = async () => { try { await czapi("service_save", {id:CZ.srvEd.id || 0, nom:$("#sn").value, nom_en:$("#sne").value, nom_ar:$("#sna").value, description:$("#sd").value, prix:$("#sp").value, cout:$("#sc").value, delai_h:+$("#sh").value, ordre:+$("#so").value, actif: $("#sa") ? $("#sa").checked : true}); CZ.srvEd = null; toast("Service enregistré."); czRe(); } catch(e) { toast(e.message); } };
}

/* ---------- Livret d'arrivée (dans la fiche du logement) ---------- */
const CZ_ETAPES_EX = ["La rue, prise depuis le dernier carrefour", "La façade et la porte d'entrée de l'immeuble", "L'étage et la porte de l'appartement", "La boîte à clés"];
function czVLivret(l){
  const b = l.livret || {etapes:[]}, c = l.carte && l.carte.ll ? l.carte : null;
  if (!b.etapes.length) b.etapes = CZ_ETAPES_EX.map(() => ({texte:"", photo:"", url:""}));
  l.livret = b;
  return `<h3>Livret d'arrivée</h3>
    <div class="hint">Au Maroc l'adresse ne suffit pas : ce livret mène l'agent et le voyageur jusqu'à la porte. Le voyageur voit le chemin et les photos dès 7 jours avant l'arrivée ; le code, les consignes et le mot de passe Wi-Fi seulement le jour d'arrivée, une fois le feu vert donné. Rien après son départ.</div>
    <label>Position exacte <span class="hint">· lien Google Maps complet ou coordonnées. Sur place, le bouton prend la position du téléphone.</span>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><input id="lpos" style="flex:1;min-width:220px" placeholder="Lien Google Maps, Plus Code (8C9G+QX Marrakech) ou 31.6295, -7.9811"><button type="button" class="btn" id="lgeo">Je suis devant la porte</button></div></label>
    <div class="hint" id="lposi">${c ? `${l.pos_fixe ? "Position fixée" : "Position relevée au premier passage de l'agent, à confirmer"} : <a href="${esc(c.maps)}" target="_blank" rel="noopener">vérifier sur la carte</a>` : "Aucune position : sans elle, ni l'agent ni le voyageur n'ont d'itinéraire."}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px">
      <label>Repère<input id="lrep" value="${esc(b.repere || "")}" placeholder="Après la mosquée, deuxième ruelle à gauche, porte verte"></label>
      <label>Stationnement<input id="lpark" value="${esc(b.parking || "")}" placeholder="Parking gardé place Bab Doukkala, 20 DH la nuit"></label>
      <label>Boîte à clés, serrure : mode d'emploi<input id="lser" value="${esc(b.serrure || "")}" placeholder="Boîte grise à droite du portail : composer le code, tirer le volet"></label>
      <label>Wi-Fi : réseau<input id="lwn" value="${esc(b.wifi_nom || "")}"></label>
      <label>Wi-Fi : mot de passe <span class="hint">· chiffré</span><input id="lwm" value="${esc(b.wifi_mdp || "")}" autocomplete="off"></label>
      <label>Numéro à appeler sur place<input id="lurg" type="tel" value="${esc(b.urgence || "")}" placeholder="+212 6 …"></label></div>
    <label>Au départ <span class="hint">· clés, poubelles, climatisation</span><textarea id="ldep" rows="2" placeholder="Remettre les clés dans la boîte, éteindre la climatisation">${esc(b.depart || "")}</textarea></label>
    <label>Règles de la maison <span class="hint">· visibles par le voyageur dès 7 jours avant l'arrivée</span><textarea id="lreg" rows="3">${esc(b.regles || "")}</textarea></label>
    <div><button type="button" class="btn ghost" id="ltyp">Remplir les champs vides avec les textes types</button> <span class="hint">en français et en anglais, à adapter au logement</span></div>
    <div class="hint" style="margin-top:6px">Étapes en photos, dans l'ordre du trajet (12 au plus) :</div><div id="let">${czEtapes(b.etapes)}</div><button type="button" class="btn ghost" id="letadd">Ajouter une étape</button>`;
}
const czEtapes = E => E.map((e, i) => `<div style="display:grid;grid-template-columns:84px 1fr 40px;gap:8px;align-items:center;margin-bottom:8px">
    <label style="width:84px;height:64px;border:1px dashed #bbb;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;font-size:.75rem;text-align:center">${e.url ? `<img src="${esc(e.url)}" alt="" style="width:100%;height:100%;object-fit:cover">` : "Photo"}<input type="file" accept="image/*" data-etp="${i}" hidden></label>
    <input data-ett="${i}" value="${esc(e.texte)}" placeholder="${esc(CZ_ETAPES_EX[i] || "Étape suivante")}"><button type="button" class="btn ghost" data-etx="${i}" title="Retirer">×</button></div>`).join("");
function czLireEtapes(){ return CZ.ed.livret.etapes.map((e, i) => Object.assign({}, e, {texte: ($(`[data-ett="${i}"]`) || {}).value ?? e.texte})); }
function czLireLivret(){ return {repere:$("#lrep").value, parking:$("#lpark").value, regles:$("#lreg").value, serrure:$("#lser").value, wifi_nom:$("#lwn").value, wifi_mdp:$("#lwm").value, urgence:$("#lurg").value, depart:$("#ldep").value, etapes:czLireEtapes().map(e => ({texte:e.texte, photo:e.photo}))}; }
function czBLivret(){
  if ($("#ltyp")) $("#ltyp").onclick = () => { let n = 0; for (const [id, txt] of Object.entries(CZ_LIVRET_TYPES)) { const el = $(id); if (el && !el.value.trim()) { el.value = txt; n++; } }
    toast(n ? n + " champ(s) rempli(s) : relisez-les avant d'enregistrer." : "Tous ces champs sont déjà remplis."); };
  const redessine = () => { $("#let").innerHTML = czEtapes(CZ.ed.livret.etapes); lier(); };
  const lier = () => {
    document.querySelectorAll("[data-etx]").forEach(b => b.onclick = () => { const E = czLireEtapes(); E.splice(+b.dataset.etx, 1); CZ.ed.livret.etapes = E; redessine(); });
    document.querySelectorAll("[data-etp]").forEach(inp => inp.onchange = async () => { const f = inp.files[0]; if (!f) return; const i = +inp.dataset.etp; CZ.ed.livret.etapes = czLireEtapes(); inp.parentElement.firstChild.textContent = "…";
      const fd = new FormData(); fd.append("photo", f);
      try { const r = await fetch("conciergerie_api.php?action=logement_photo", {method:"POST", credentials:"same-origin", headers:{"X-Requested-With":"amaya"}, body:fd}); const d = await r.json(); if (!r.ok) throw new Error(d.error || "Erreur");
        Object.assign(CZ.ed.livret.etapes[i], {photo:d.photo, url:d.url}); } catch(e) { toast(e.message); } redessine(); });
  };
  lier();
  $("#letadd").onclick = () => { const E = czLireEtapes(); if (E.length >= 12) return toast("12 étapes au plus."); E.push({texte:"", photo:"", url:""}); CZ.ed.livret.etapes = E; redessine(); };
  $("#lgeo").onclick = () => { if (!navigator.geolocation) return toast("Ce navigateur ne donne pas la position.");
    $("#lposi").textContent = "Position en cours…";
    navigator.geolocation.getCurrentPosition(p => { const ll = p.coords.latitude.toFixed(6) + ", " + p.coords.longitude.toFixed(6); $("#lpos").value = ll;
      $("#lposi").innerHTML = `Position prise à ${Math.round(p.coords.accuracy)} m près : ${esc(ll)}. Elle sera enregistrée avec le logement.${p.coords.accuracy > 50 ? " Précision faible : sortez à découvert et recommencez." : ""}`; },
      () => $("#lposi").textContent = "Position refusée ou indisponible : autorisez la localisation, ou collez un lien Google Maps.", {enableHighAccuracy:true, timeout:15000, maximumAge:0}); };
}

/* ---------- Messages du voyageur et assistant ---------- */
function czVMessages(D){
  const M = D.messages || [], qui = {voyageur:"Voyageur", assistant:"Assistant", gestion:"Vous"};
  return `<div class="card" id="msgs"><h2>Messages</h2>
    ${M.length ? M.map(m => `<div style="padding:8px 10px;margin:4px 0;max-width:85%;white-space:pre-line;${m.auteur === "voyageur" ? "background:#f4f3f1" : "margin-left:auto;border:1px solid #e2e2e2"}"><div class="hint">${qui[m.auteur] || m.auteur} · ${esc(String(m.created_at).slice(0, 16).replace("T", " "))}${+m.a_traiter ? ` <span class="pill p-warn">À traiter</span>` : ""}</div>${esc(m.texte)}</div>`).join("") : `<div class="muted">Aucune question posée sur la page du voyageur.</div>`}
    ${CZ.ini.gerer ? `<label>Répondre sur sa page<textarea id="mrt" rows="2"></textarea></label><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="primary" id="mrs">Envoyer</button>${M.some(m => +m.a_traiter) ? `<button class="btn ghost" id="mrk">Marquer comme traité</button>` : ""}</div>` : ""}
    <h3 style="margin-top:14px">Réponse WhatsApp</h3>
    ${D.assistant ? `<label>Message reçu du voyageur sur WhatsApp<textarea id="wbr" rows="2" placeholder="Collez ici son message"></textarea></label><button class="btn" id="wbp">Proposer une réponse</button>
      <div id="wbo" hidden style="display:grid;gap:8px;margin-top:8px"><div class="hint" id="wbi"></div><textarea id="wbt" rows="4"></textarea><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="primary" id="wbw" target="_blank" rel="noopener" href="#">Ouvrir WhatsApp</a><button class="btn ghost" id="wbc">Copier</button></div></div>`
      : `<div class="hint">Activez l'assistant dans les réglages de la conciergerie pour qu'il prépare vos réponses.</div>`}</div>`;
}
function czBMessages(r){
  if ($("#mrs")) $("#mrs").onclick = async () => { try { await czapi("message_repondre", {id:r.id, texte:$("#mrt").value}); toast("Réponse visible sur la page du voyageur."); czDetail(r.id); } catch(e) { toast(e.message); } };
  if ($("#mrk")) $("#mrk").onclick = async () => { try { await czapi("message_repondre", {id:r.id, texte:""}); czDetail(r.id); } catch(e) { toast(e.message); } };
  if (!$("#wbp")) return;
  let num = "";
  const maj = () => { const t = $("#wbt").value; if (num) $("#wbw").href = num + "?text=" + encodeURIComponent(t); else $("#wbw").hidden = true; };
  $("#wbp").onclick = async e => { e.target.disabled = true;
    try { const b = await czapi("wa_brouillon", {id:r.id, message:$("#wbr").value}); num = b.wa ? b.wa.split("?")[0] : "";
      $("#wbo").hidden = false; $("#wbt").value = b.reponse; $("#wbi").textContent = (b.transmettre ? "À vérifier : l'assistant pense qu'il faut votre intervention. " : "") + "Relisez et corrigez avant d'envoyer." + (num ? "" : " Pas de numéro WhatsApp sur ce séjour : copiez la réponse.");
      maj(); } catch(x) { toast(x.message.replace(/^champ:.*/, "Collez d'abord le message du voyageur.")); }
    e.target.disabled = false; };
  $("#wbt").oninput = maj;
  $("#wbc").onclick = () => czCopier($("#wbt").value, "Réponse copiée.");
}

/* ---------- Rapports ---------- */
const czN = v => v == null ? "–" : (+v).toLocaleString("fr-FR", {maximumFractionDigits:1});
function czPeriode(k){
  const d = new Date(), y = d.getFullYear(), m = d.getMonth(), iso = x => czIso(x);
  if (k === "30j") return [iso(new Date(y, m, d.getDate() - 29)), iso(d)];
  if (k === "mois") return [iso(new Date(y, m, 1)), iso(d)];
  if (k === "mois-1") return [iso(new Date(y, m - 1, 1)), iso(new Date(y, m, 0))];
  if (k === "trim") { const q = Math.floor(m / 3) * 3; return [iso(new Date(y, q, 1)), iso(d)]; }
  if (k === "annee") return [iso(new Date(y, 0, 1)), iso(d)];
  return null;
}
function czVRap(){
  const R = CZ.rap, T = R.total, F = CZ.rapF;
  const kpi = (v, l, s) => `<div class="card" style="display:grid;gap:2px;padding:14px 16px"><span class="hint">${l}</span><b style="font-size:26px;line-height:1.1">${v}</b>${s ? `<span class="hint">${s}</span>` : ""}</div>`;
  const head = F.par === "responsable" ? ["Responsable", "Missions", "Faites", "En retard", "Ménages", "Durée moyenne", "Position éloignée", "Propreté", "Avis"]
    : [F.par === "proprietaire" ? "Propriétaire" : "Logement", "Nuits", "Occupation", "Séjours", "Ménages faits", "Retards", "Propreté", "Séjour", "Incidents", "Dépenses", "Services vendus", "Marge services", "Séjours avec commande"];
  const row = r => F.par === "responsable" ? [esc(r.nom), r.missions, r.faites, r.retards ? `<b style="color:#8e3b2f">${r.retards}</b>` : 0, r.menages, r.duree_moyenne != null ? r.duree_moyenne + " min" : "–", r.loin || 0, r.proprete != null ? czN(r.proprete) + "/5" : "–", r.avis]
    : [esc(r.nom) + (r.detail ? `<div class="hint">${esc(r.detail.join(", "))}</div>` : r.proprietaire ? `<div class="hint">${esc(r.proprietaire)}</div>` : ""), r.nuits, r.occupation + " %", r.sejours, `${r.menages_faits}/${r.menages}`, r.retards ? `<b style="color:#8e3b2f">${r.retards}</b>` : 0,
       r.proprete != null ? czN(r.proprete) + "/5" : "–", r.note_sejour != null ? czN(r.note_sejour) + "/5" : "–", r.incidents_ouverts ? `${r.incidents} (${r.incidents_ouverts} ouvert)` : r.incidents, czDH(r.depenses), czDH(r.ca_services), czDH(r.marge_services), r.taux_commande + " %"];
  return `<div class="card no-print"><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      <label>Période<select id="rp"><option value="">Personnalisée</option><option value="30j" ${F.k === "30j" ? "selected" : ""}>30 derniers jours</option><option value="mois">Ce mois</option><option value="mois-1">Mois dernier</option><option value="trim">Ce trimestre</option><option value="annee">Cette année</option></select></label>
      <label>Du<input type="date" id="rf" value="${F.from}"></label><label>Au<input type="date" id="rt" value="${F.to}"></label>
      <label>Par<select id="rb"><option value="logement" ${F.par === "logement" ? "selected" : ""}>Logement</option><option value="proprietaire" ${F.par === "proprietaire" ? "selected" : ""}>Propriétaire</option><option value="responsable" ${F.par === "responsable" ? "selected" : ""}>Responsable</option></select></label>
      <button class="primary" id="rgo">Afficher</button><button class="btn" id="rpr">Imprimer</button><button class="btn ghost" id="rcsv">Exporter (tableur)</button></div></div>
    <div class="card"><h2 style="margin:0">Rapport du ${esc(czD(R.from))} au ${esc(czD(R.to))}</h2><div class="hint">${R.jours} jours · ${T.logements} logements</div></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
      ${kpi(czN(T.nuits), "Nuits louées", T.occupation + " % d'occupation")}${kpi(czN(T.sejours), "Séjours", czN(T.voyageurs) + " voyageurs")}
      ${kpi(`${T.menages_faits}/${T.menages}`, "Ménages faits", T.retards ? T.retards + " intervention(s) en retard" : "aucun retard")}
      ${kpi(T.proprete != null ? czN(T.proprete) + "/5" : "–", "Propreté notée", T.avis + " avis")}${kpi(czN(T.incidents), "Signalements", czDH(T.depenses) + " de dépenses")}
      ${kpi(czDH(T.ca_services), "Services vendus", "marge " + czDH(T.marge_services))}${kpi(T.taux_commande + " %", "Séjours avec commande", czN(T.commandes) + " articles")}</div>
    ${R.rentabilite && R.rentabilite.cout > 0 ? (K => `<div class="card" style="border-left:4px solid var(--laiton,#C6A15B)"><h3 style="margin:0 0 6px">Ce que Keylia vous rapporte</h3>
      <div style="display:flex;flex-wrap:wrap;gap:22px;align-items:baseline"><div><div style="font-size:1.7rem;font-weight:600">${czDH(K.marge)}</div><div class="muted">de marge sur les services vendus aux voyageurs</div></div>
      <div><div style="font-size:1.7rem;font-weight:600">${czDH(K.cout)}</div><div class="muted">coût de Keylia sur la période (${K.logements_actifs} logement${K.logements_actifs > 1 ? "s" : ""} actif${K.logements_actifs > 1 ? "s" : ""} à ${czN(K.tarif)} DH HT par mois)</div></div>
      ${K.multiple != null ? `<div><div style="font-size:1.7rem;font-weight:600">${K.multiple >= 1 ? "× " + czN(K.multiple) : czN(Math.round(K.multiple * 100)) + " %"}</div><div class="muted">${K.multiple >= 1 ? "l'abonnement est remboursé par les ventes" : "de l'abonnement couvert par les ventes"}</div></div>` : ""}</div>
      ${K.multiple != null && K.multiple < 1 ? `<div class="hint" style="margin-top:8px">Pour couvrir l'abonnement, proposez l'eau, les fruits et le transfert depuis l'aéroport dans le livret de chaque logement.</div>` : ""}</div>`)(R.rentabilite) : ""}
    ${R.alertes.length ? `<div class="card accent"><h3 style="margin:0 0 6px">À regarder</h3><ul style="margin:0">${R.alertes.map(a => `<li>${esc(a)}</li>`).join("")}</ul></div>` : `<div class="card"><span class="pill p-ok">Tout est en ordre sur la période</span></div>`}
    <div class="card"><h3 style="margin:0 0 8px">Détail par ${F.par === "responsable" ? "responsable" : F.par === "proprietaire" ? "propriétaire" : "logement"}</h3><div class="scroll"><table class="tbl" id="rtab"><thead><tr>${head.map(h => `<th>${h}</th>`).join("")}</tr></thead><tbody>
      ${R.lignes.map(r => `<tr>${row(r).map(c => `<td>${c}</td>`).join("")}</tr>`).join("") || `<tr><td colspan="${head.length}" class="muted">Rien sur la période.</td></tr>`}</tbody></table></div></div>
    <div class="card"><h3 style="margin:0 0 8px">Ventes complémentaires</h3>${R.services.length ? `<table class="tbl"><thead><tr><th>Service</th><th>Quantité</th><th>Encaissé</th><th>Marge</th></tr></thead><tbody>${R.services.map(x => `<tr><td>${esc(x.nom)}</td><td>${x.quantite}</td><td>${czDH(x.ca)}</td><td>${czDH(x.marge)}</td></tr>`).join("")}</tbody></table>` : `<div class="muted">Aucune vente sur la période. Pensez à proposer l'eau, les fruits et le transfert dans le livret.</div>`}</div>
    <style>@media print{.no-print,.top,nav,.tabs,.secs,.demo-ruban{display:none!important}.card{break-inside:avoid}}</style>`;
}
function czBRap(){
  $("#rp").onchange = () => { const p = czPeriode($("#rp").value); if (p) { $("#rf").value = p[0]; $("#rt").value = p[1]; } };
  $("#rgo").onclick = () => { CZ.rapF = {from:$("#rf").value, to:$("#rt").value, par:$("#rb").value}; czRe(); };
  $("#rb").onchange = $("#rgo").onclick;
  $("#rpr").onclick = () => window.print();
  $("#rcsv").onclick = () => {
    const rows = [...document.querySelectorAll("#rtab tr")].map(tr => [...tr.children].map(td => '"' + td.innerText.replace(/\n/g, " ").replace(/"/g, '""') + '"').join(";"));
    const a = document.createElement("a"); a.href = URL.createObjectURL(new Blob(["﻿" + rows.join("\r\n")], {type:"text/csv;charset=utf-8"}));
    a.download = `keylia-rapport-${CZ.rapF.par}-${CZ.rapF.from}-${CZ.rapF.to}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  };
}

/* ---------- Équipe terrain ---------- */
const czTel = t => { const d = String(t || "").replace(/\D/g, ""); return /^212\d{9}$/.test(d) ? `+212 ${d[3]} ${d.slice(4, 6)} ${d.slice(6, 8)} ${d.slice(8, 10)} ${d.slice(10)}` : t; };
function czVEq(){
  const E = CZ.eq, ed = CZ.eqEd, g = CZ.ini.gerer;
  const form = ed ? `<div class="card accent" style="display:grid;gap:10px"><h2 style="margin:0">${ed.id ? "Modifier " + esc(ed.prenom) : "Ajouter une personne"}</h2>
      <div class="row3"><label>Prénom<input id="qp" value="${esc(ed.prenom || "")}"></label><label>Nom<input id="qn" value="${esc(ed.nom || "")}"></label><label>Ville<input id="qv" value="${esc(ed.ville || "")}" placeholder="Marrakech"></label></div>
      <div class="row3"><label>Portable WhatsApp<input id="qt" type="tel" value="${esc(czTel(ed.telephone || ""))}" placeholder="+212 6 …"></label><label>E-mail <span class="hint">pour le code de connexion</span><input id="qe" type="email" value="${esc(ed.email || "")}"></label>
      ${ed.id ? `<label class="chk" style="align-self:end"><input type="checkbox" id="qa" ${ed.actif ? "checked" : ""}> Active dans l'équipe</label>` : "<span></span>"}</div>
      <div style="display:flex;gap:8px"><button class="primary" id="qs">Enregistrer</button><button class="btn ghost" id="qx">Annuler</button></div></div>` : "";
  const st = a => !a.actif ? `<span class="pill">Désactivée</span>` : a.inscrit ? `<span class="pill p-ok">Appli installée</span>` : `<span class="pill p-todo">Pas encore connectée</span>`;
  return `<div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h2 style="margin:0">Équipe terrain</h2>${g ? `<button class="primary" id="qnew">Ajouter une personne</button>` : ""}</div>
      <div class="muted" style="margin-top:6px">Femmes de ménage, gouvernantes, techniciens : chacun reçoit ses missions sur son téléphone, en français ou en darija. Pour une intervention ponctuelle, envoyez simplement la mission par WhatsApp depuis l'onglet Missions : pas besoin de compte.</div></div>
    ${form}
    <div class="card"><div class="scroll"><table class="tbl"><thead><tr><th>Personne</th><th>Contact</th><th>30 derniers jours</th><th>7 prochains jours</th><th>Propreté notée</th><th>Appli</th><th></th></tr></thead><tbody>
    ${E.equipe.map(a => `<tr${a.actif ? "" : ` style="opacity:.55"`}><td><b>${esc(a.prenom)} ${esc(a.nom)}</b>${a.ville ? `<div class="hint">${esc(a.ville)}</div>` : ""}</td>
      <td>${esc(czTel(a.telephone))}${a.email ? `<div class="hint">${esc(a.email)}</div>` : ""}</td>
      <td>${a.faites} mission${a.faites > 1 ? "s" : ""}${a.retards ? `<div><span class="pill p-bad">${a.retards} en retard</span></div>` : ""}</td><td>${a.a_venir || "–"}</td>
      <td>${a.proprete != null ? czN(a.proprete) + "/5" + `<div class="hint">${a.avis} avis</div>` : "–"}</td><td>${st(a)}</td>
      <td style="white-space:nowrap">${g ? `<button class="btn" data-qed="${a.id}">Modifier</button> ` : ""}${a.wa && a.actif ? `<a class="btn ghost" href="${esc(a.wa)}" target="_blank" rel="noopener">Inviter par WhatsApp</a>` : ""}</td></tr>`).join("") || `<tr><td colspan="7" class="muted">Personne pour l'instant. Ajoutez votre première intervenante.</td></tr>`}</tbody></table></div></div>
    <div class="card" style="display:grid;gap:8px"><h3 style="margin:0">Connexion à l'appli</h3>
      <div class="muted">Chaque personne ouvre ce lien sur son téléphone et saisit le code de la conciergerie avec son prénom, son nom et son numéro. Un code de confirmation lui est envoyé par e-mail à la première connexion.</div>
      <div><b>Lien :</b> ${esc(E.lien)} <button class="btn ghost" id="qcl">Copier</button></div>
      ${g ? `<div class="row3"><label>Code de la conciergerie<input id="qc" value="${esc(E.code)}" style="font-weight:600;letter-spacing:.08em;text-transform:uppercase"></label><div style="align-self:end"><button class="btn" id="qcs">Changer le code</button></div>
        <div class="hint" style="align-self:end">Changer le code ne déconnecte personne : il bloque seulement les nouvelles connexions avec l'ancien.</div></div>` : `<div><b>Code :</b> ${esc(E.code)}</div>`}</div>`;
}
function czBEq(){
  const E = CZ.eq;
  if ($("#qnew")) $("#qnew").onclick = () => { CZ.eqEd = {id:0}; czRe(); };
  document.querySelectorAll("[data-qed]").forEach(b => b.onclick = () => { CZ.eqEd = Object.assign({}, E.equipe.find(a => a.id === +b.dataset.qed)); czRe(); });
  if ($("#qx")) $("#qx").onclick = () => { CZ.eqEd = null; czRe(); };
  if ($("#qs")) $("#qs").onclick = async () => { try { await czapi("equipe_save", {id:CZ.eqEd.id, prenom:$("#qp").value, nom:$("#qn").value, ville:$("#qv").value, telephone:$("#qt").value, email:$("#qe").value, actif:$("#qa") ? $("#qa").checked : true});
    CZ.eqEd = null; CZ.ini = null; toast("Équipe mise à jour."); czRe(); } catch(e) { toast(e.message); } };
  $("#qcl").onclick = () => czCopier(E.lien, "Lien copié.");
  if ($("#qcs")) $("#qcs").onclick = async () => { try { await api("save_code", {code:$("#qc").value}); toast("Code changé."); czRe(); } catch(e) { toast(e.message); } };
}

/* ---------- Taxe de séjour ---------- */
function czVTs(){
  const T = CZ.ts, R = T.reglages, g = CZ.ini.gerer, src = CZ.ini.sources || {};
  const mois = new Date(CZ.tsM + "-15").toLocaleDateString("fr-FR", {month:"long", year:"numeric"});
  const reg = g ? `<details class="card" ${T.sans_tarif.length ? "open" : ""}><summary><b>Tarifs de vos communes</b>${T.sans_tarif.length ? ` <span class="pill p-todo">${T.sans_tarif.length} à renseigner</span>` : ""}</summary>
      <div class="muted" style="margin:8px 0">Chaque commune fixe son tarif par nuit et par personne, ses exonérations et la fréquence du reversement. Reprenez ceux de l'arrêté de votre commune : Keylia ne fixe aucun montant.</div>
      <table class="tbl"><thead><tr><th>Commune</th><th>Tarif (DH par nuit et par personne)</th><th>Exonérés : moins de (ans)</th></tr></thead><tbody>
      ${Object.entries(R.communes).map(([v, c]) => `<tr><td>${esc(v)}</td><td><input data-tst="${esc(v)}" inputmode="decimal" value="${c.tarif || ""}" style="max-width:120px"></td><td><input data-tsa="${esc(v)}" type="number" min="0" max="25" value="${c.age || ""}" placeholder="aucune" style="max-width:120px"></td></tr>`).join("") || `<tr><td colspan="3" class="muted">Ajoutez d'abord vos logements avec leur ville.</td></tr>`}</tbody></table>
      <div style="margin-top:10px"><b>Plateformes qui collectent et reversent elles-mêmes la taxe</b><div class="hint">Cochez-les seulement si la plateforme le fait pour vos logements : ces séjours sont alors comptés à part, sans montant à reverser.</div>
      <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:6px">${Object.entries(src).filter(([k]) => k !== "direct").map(([k, l]) => `<label class="chk"><input type="checkbox" data-tsp="${k}" ${R.plateformes.includes(k) ? "checked" : ""}> ${esc(l)}</label>`).join("")}</div></div>
      <div style="margin-top:12px"><button class="primary" id="tss">Enregistrer les tarifs</button></div></details>` : "";
  return `<div class="card no-print"><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end"><label>Mois<input type="month" id="tsm" value="${CZ.tsM}"></label>
      <button class="btn" id="tspr">Imprimer l'état</button><button class="btn ghost" id="tscsv">Exporter (tableur)</button></div></div>
    <div class="no-print">${reg}</div>
    <div class="card"><h2 style="margin:0">Taxe de séjour · ${esc(mois)}</h2><div class="hint">${esc(T.agence || "")} · état préparé par Keylia d'après les séjours enregistrés</div></div>
    ${T.sans_tarif.length ? `<div class="card accent no-print">Tarif non renseigné pour ${T.sans_tarif.map(esc).join(", ")} : les montants de ces communes sont à zéro.</div>` : ""}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
      <div class="card" style="display:grid;gap:2px;padding:14px 16px"><span class="hint">À reverser aux communes</span><b style="font-size:26px">${czDH(T.total.a_reverser)}</b></div>
      <div class="card" style="display:grid;gap:2px;padding:14px 16px"><span class="hint">Nuitées taxables</span><b style="font-size:26px">${czN(T.total.nuitees)}</b></div>
      <div class="card" style="display:grid;gap:2px;padding:14px 16px"><span class="hint">Reversé par les plateformes</span><b style="font-size:26px">${czDH(T.total.plateforme)}</b></div></div>
    <div class="card"><h3 style="margin:0 0 8px">Par commune</h3><table class="tbl"><thead><tr><th>Commune</th><th>Tarif</th><th>Séjours</th><th>Nuitées taxables</th><th>Reversé par les plateformes</th><th>À reverser</th></tr></thead><tbody>
      ${T.communes.map(c => `<tr><td>${esc(c.commune)}</td><td>${czDH(c.tarif)}</td><td>${c.sejours}</td><td>${czN(c.nuitees)}</td><td>${czDH(c.plateforme)}</td><td><b>${czDH(c.a_reverser)}</b></td></tr>`).join("") || `<tr><td colspan="6" class="muted">Aucun séjour sur le mois.</td></tr>`}</tbody></table></div>
    <div class="card"><h3 style="margin:0 0 8px">Détail des séjours</h3><div class="scroll"><table class="tbl" id="tstab"><thead><tr><th>Logement</th><th>Commune</th><th>Séjour</th><th>Source</th><th>Nuits dans le mois</th><th>Personnes</th><th>Exonérées</th><th>Nuitées taxables</th><th>Montant</th></tr></thead><tbody>
      ${T.lignes.map(l => `<tr><td>${esc(l.logement)}</td><td>${esc(l.ville)}</td><td>${esc(czD(l.arrivee))} au ${esc(czD(l.depart))}</td><td>${esc(src[l.source] || l.source)}</td><td>${l.nuits}</td><td>${l.personnes}</td>
        <td>${l.ages_connus ? l.exoneres + (l.ages_connus < l.personnes ? ` <span class="hint">(${l.ages_connus} âge${l.ages_connus > 1 ? "s" : ""} connu${l.ages_connus > 1 ? "s" : ""})</span>` : "") : `<span class="hint">sans fiche</span>`}</td><td>${l.nuitees}</td><td>${l.plateforme ? `<span class="hint">reversé par la plateforme</span><div>${czDH(l.montant)}</div>` : czDH(l.montant)}</td></tr>`).join("")}</tbody></table></div>
      <div class="hint" style="margin-top:6px">Les personnes exonérées sont comptées d'après les dates de naissance des fiches voyageurs. Sans fiche, toutes les personnes du séjour sont comptées.</div></div>
    <style>@media print{.no-print,.top,nav,.tabs,.secs,.demo-ruban{display:none!important}.card{break-inside:avoid}}</style>`;
}
function czBTs(){
  $("#tsm").onchange = () => { if ($("#tsm").value) { CZ.tsM = $("#tsm").value; czRe(); } };
  $("#tspr").onclick = () => window.print();
  $("#tscsv").onclick = () => {
    const rows = [...document.querySelectorAll("#tstab tr")].map(tr => [...tr.children].map(td => '"' + td.innerText.replace(/\n/g, " ").replace(/"/g, '""') + '"').join(";"));
    const a = document.createElement("a"); a.href = URL.createObjectURL(new Blob(["﻿" + rows.join("\r\n")], {type:"text/csv;charset=utf-8"}));
    a.download = `keylia-taxe-sejour-${CZ.tsM}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  };
  if ($("#tss")) $("#tss").onclick = async () => {
    const communes = {}; document.querySelectorAll("[data-tst]").forEach(i => { communes[i.dataset.tst] = {tarif:i.value, age:(document.querySelector(`[data-tsa="${CSS.escape(i.dataset.tst)}"]`) || {}).value || 0}; });
    const plateformes = [...document.querySelectorAll("[data-tsp]:checked")].map(i => i.dataset.tsp);
    try { await czapi("taxe_reglages", {communes, plateformes}); toast("Tarifs enregistrés."); czRe(); } catch(e) { toast(e.message); }
  };
}

/* ---------- Fiche d'un propriétaire : messages, devis, rapports ---------- */
const czPropDe = lid => +((CZ.ini.logements.find(l => +l.id === +lid) || {}).proprietaire_id) || 0;
function czNotif(r, quoi){
  toast(quoi + (r.mail ? " et prévenu par e-mail." : "."));
  if (r.wa) { const b = document.createElement("div"); b.className = "card accent"; b.style.cssText = "position:fixed;right:16px;bottom:80px;z-index:200;max-width:340px;display:grid;gap:8px";
    b.innerHTML = `<b>Prévenir aussi par WhatsApp ?</b><div style="display:flex;gap:8px"><a class="primary btn" href="${esc(r.wa)}" target="_blank" rel="noopener">Ouvrir WhatsApp</a><button class="btn ghost">Non merci</button></div>`;
    document.body.appendChild(b); b.querySelector("a").onclick = () => setTimeout(() => b.remove(), 300); b.querySelector("button").onclick = () => b.remove(); }
}
const czPfSt = d => d.statut === "envoye" ? `<span class="pill p-todo">En attente de sa réponse</span>` : d.statut === "refuse" ? `<span class="pill p-bad">Refusé</span>`
  : d.paiement === "regle" ? `<span class="pill p-ok">Accepté · réglé</span>` : `<span class="pill p-warn">Accepté · à régler</span>`;
function czVPf(){
  const F = CZ.pf, P = F.proprietaire, g = CZ.ini.gerer, R = CZ.pfRap;
  const optL = `<option value="">Tous ses logements</option>${F.logements.map(l => `<option value="${l.id}">${esc(l.nom)}</option>`).join("")}`;
  const msgs = F.messages.map(m => `<div class="pf-m ${m.auteur === "proprietaire" ? "lui" : "nous"}"><div class="pf-b">${esc(m.texte).replace(/\n/g, "<br>")}
      ${m.photos.length ? `<div class="pf-ph">${m.photos.map(x => `<a href="${esc(x.url)}" target="_blank" rel="noopener"><img src="${esc(x.mini)}" alt=""></a>`).join("")}</div>` : ""}</div>
      <div class="hint">${esc(m.auteur === "proprietaire" ? P.nom : m.nom || "Conciergerie")} · ${esc(czD(m.created_at))} ${esc(String(m.created_at).slice(11, 16))}${m.auteur === "conciergerie" ? (m.lu_at ? " · lu" : " · pas encore lu") : ""}</div></div>`).join("");
  const apercu = R ? `<div class="card accent" style="display:grid;gap:10px"><h3 style="margin:0">Rapport du ${esc(czD(R.from))} au ${esc(czD(R.to))}</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px">${[[R.c.total.nuits, "nuits louées"], [R.c.total.sejours, "séjours"], [R.c.total.menages_faits, "ménages faits"], [czDH(R.c.total.depenses), "de dépenses"], [czDH(R.c.taxe.montant), "de taxe de séjour"]].map(([v, l]) => `<div><b style="font-size:22px">${v}</b><div class="hint">${l}</div></div>`).join("")}</div>
      <div class="hint">${R.c.logements.map(l => `${esc(l.nom)} : ${l.nuits} nuits, ${l.occupation} % d'occupation${l.proprete != null ? ", propreté " + czN(l.proprete) + "/5" : ""}`).join(" · ")}</div>
      <div class="hint">${R.c.photos.length} photo(s) des dernières interventions · ${R.c.avis.length} avis de voyageurs · ${R.c.incidents.length} signalement(s)</div>
      <label>Votre mot pour le propriétaire<textarea id="prn" rows="3" placeholder="Un bon mois : la villa a été louée 27 nuits, les voyageurs saluent la propreté…"></textarea></label>
      <label class="chk"><input type="checkbox" id="prp" checked> Joindre les photos</label>
      <div style="display:flex;gap:8px"><button class="primary" id="prgo">Envoyer le rapport</button><button class="btn ghost" id="prx">Annuler</button></div></div>` : "";
  return `<button class="btn ghost" id="pfback" style="align-self:flex-start">‹ Propriétaires</button>
    <div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline"><h2 style="margin:0">${esc(P.nom)}</h2>${+P.lien ? `<span class="pill p-ok">Espace actif</span>` : `<span class="pill">Espace pas encore ouvert</span>`}</div>
      <div class="muted">${esc(P.email || "pas d'e-mail")} · ${esc(P.telephone || "pas de téléphone")} · ${F.logements.map(l => esc(l.nom)).join(", ") || "aucun logement"}</div>
      <div class="hint">Chaque message, devis ou rapport le prévient par e-mail avec le lien de son espace, et vous propose de le prévenir aussi par WhatsApp.</div></div>
    <div class="pf-grid"><div class="card" style="display:grid;gap:10px"><h3 style="margin:0">Messages</h3><div class="pf-fil" id="pffil">${msgs || `<div class="muted">Aucun message pour l'instant.</div>`}</div>
      ${g ? `<textarea id="pfm" rows="3" placeholder="Votre message…"></textarea><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><select id="pfl">${optL}</select><label class="btn" style="cursor:pointer">Photos<input type="file" id="pff" accept="image/*" multiple hidden></label><span class="hint" id="pffn"></span><button class="primary" id="pfs">Envoyer</button></div>` : ""}</div>
    <div style="display:grid;gap:14px;align-content:start">
      <div class="card" style="display:grid;gap:10px"><div style="display:flex;justify-content:space-between;gap:8px;align-items:center"><h3 style="margin:0">Devis avant travaux</h3>${g ? `<button class="btn" id="pdn">Nouveau devis</button>` : ""}</div>
        <div id="pdf" hidden style="display:grid;gap:8px"><select id="pdl">${optL}</select><input id="pdt" placeholder="Objet : remplacement du chauffe-eau"><div class="row3"><input id="pdm" inputmode="decimal" placeholder="Montant en DH"><input id="pdp" placeholder="Artisan ou entreprise"><span></span></div>
          <textarea id="pdd" rows="3" placeholder="Ce qui est constaté, ce qui sera fait, le délai"></textarea><label class="btn" style="cursor:pointer">Photos du problème<input type="file" id="pdph" accept="image/*" multiple hidden></label>
          <div style="display:flex;gap:8px"><button class="primary" id="pds">Envoyer pour accord</button><button class="btn ghost" id="pdx">Annuler</button></div></div>
        ${F.devis.map(d => `<div class="pf-d"><div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><b>${esc(d.titre)}</b><b>${czDH(d.montant)}</b></div>
          <div class="hint">${esc(d.logement || "")}${d.prestataire ? " · " + esc(d.prestataire) : ""} · envoyé le ${esc(czD(d.created_at))}${d.decide_at ? " · réponse le " + esc(czD(d.decide_at)) : ""}</div>
          ${d.reponse ? `<div class="hint">« ${esc(d.reponse)} »</div>` : ""}<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:4px">${czPfSt(d)}
          ${g && (d.statut === "envoye" || d.paiement === "a_regler") ? `<button class="btn ghost" data-pdr="${d.id}">Relancer</button>` : ""}${g && d.paiement === "a_regler" ? `<button class="btn" data-pdok="${d.id}">Marquer réglé</button>` : ""}</div></div>`).join("") || `<div class="muted">Aucun devis.</div>`}</div>
      <div class="card" style="display:grid;gap:10px"><div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap"><h3 style="margin:0">Rapports envoyés</h3>
        ${g ? `<div style="display:flex;gap:6px;align-items:center"><input type="month" id="prm" value="${CZ.pfM || czIso(new Date(new Date().getFullYear(), new Date().getMonth(), 0)).slice(0, 7)}"><button class="btn" id="pra">Préparer le rapport</button></div>` : ""}</div>
        ${F.rapports.map(r => `<div style="display:flex;justify-content:space-between;gap:8px;border-top:1px solid var(--line);padding-top:6px"><span>${esc(r.titre)}<div class="hint">${r.type === "visite" ? "Rapport de visite" : "Rapport de période"} · ${esc(czD(r.created_at))} · ${esc(r.par)}</div></span>${r.lu_at ? `<span class="pill p-ok">Lu</span>` : `<span class="pill">Pas encore lu</span>`}</div>`).join("") || `<div class="muted">Aucun rapport envoyé.</div>`}</div></div></div>
    ${apercu}`;
}
function czBPf(){
  const F = CZ.pf, id = F.proprietaire.id, fil = $("#pffil"); if (fil) fil.scrollTop = fil.scrollHeight;
  $("#pfback").onclick = () => { CZ.pfId = null; CZ.pf = null; CZ.pfRap = null; czRe(); };
  if ($("#pff")) $("#pff").onchange = () => $("#pffn").textContent = $("#pff").files.length ? $("#pff").files.length + " photo(s)" : "";
  if ($("#pfs")) $("#pfs").onclick = async () => { const fd = new FormData(); fd.append("id", id); fd.append("texte", $("#pfm").value); fd.append("logement_id", $("#pfl").value);
    for (const f of $("#pff").files) fd.append("photos[]", await shrink(f), "photo.jpg");
    try { const r = await czapi("prop_message", fd); czRe(); setTimeout(() => czNotif(r, "Message envoyé"), 400); } catch(e) { toast(e.message); } };
  if ($("#pdn")) $("#pdn").onclick = () => { $("#pdf").hidden = false; $("#pdt").focus(); };
  if ($("#pdx")) $("#pdx").onclick = () => $("#pdf").hidden = true;
  if ($("#pds")) $("#pds").onclick = async () => { const fd = new FormData(); fd.append("id", id); fd.append("logement_id", $("#pdl").value); fd.append("titre", $("#pdt").value); fd.append("montant", $("#pdm").value); fd.append("prestataire", $("#pdp").value); fd.append("description", $("#pdd").value);
    for (const f of $("#pdph").files) fd.append("photos[]", await shrink(f), "photo.jpg");
    try { const r = await czapi("prop_devis_save", fd); czRe(); setTimeout(() => czNotif(r, "Devis envoyé pour accord"), 400); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-pdr]").forEach(b => b.onclick = async () => { try { czNotif(await czapi("prop_devis_relancer", {id:+b.dataset.pdr}), "Rappel envoyé"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-pdok]").forEach(b => b.onclick = async () => { try { await czapi("prop_devis_regle", {id:+b.dataset.pdok}); toast("Devis marqué réglé."); czRe(); } catch(e) { toast(e.message); } });
  if ($("#pra")) $("#pra").onclick = async () => { const v = $("#prm").value; if (!v) return; CZ.pfM = v; const [y, m] = v.split("-").map(Number), from = v + "-01", to = czIso(new Date(y, m, 0));
    try { CZ.pfRap = {from, to, c:await czapi("prop_rapport_apercu", null, `id=${id}&from=${from}&to=${to}`)}; czRe(); } catch(e) { toast(e.message); } };
  if ($("#prx")) $("#prx").onclick = () => { CZ.pfRap = null; czRe(); };
  if ($("#prgo")) $("#prgo").onclick = async () => { const R = CZ.pfRap;
    try { const r = await czapi("prop_rapport_envoyer", {id, type:"mensuel", from:R.from, to:R.to, note:$("#prn").value, sans_photos:!$("#prp").checked}); CZ.pfRap = null; czRe(); setTimeout(() => czNotif(r, "Rapport envoyé"), 400); } catch(e) { toast(e.message); } };
}

/* ---------- Linge et entretien ---------- */
function czVInt(){
  const I = CZ.int, L = I.linge, g = CZ.ini.gerer, logs = CZ.ini.logements.filter(l => l.actif), ag = CZ.ini.agents || [];
  const A = L.articles, enCours = L.envois.filter(e => e.statut === "envoye"), faits = L.envois.filter(e => e.statut === "retourne");
  const alerte = A.filter(a => a.alerte);
  const ed = CZ.entEd;
  const entForm = ed ? `<div class="card accent" style="display:grid;gap:10px"><h3 style="margin:0">${ed.id ? "Modifier l'entretien" : "Nouvel entretien programmé"}</h3>
      ${ed.id ? "" : `<label>Partir d'un modèle<select id="emod"><option value="">Choisir…</option>${I.modeles.map(([t, f], i) => `<option value="${i}">${esc(t)} (tous les ${f} jours)</option>`).join("")}</select></label>`}
      <div class="row3"><label>Logement<select id="elog">${logs.map(l => `<option value="${l.id}" ${+ed.logement_id === +l.id ? "selected" : ""}>${esc(l.nom)}</option>`).join("")}</select></label><label>Ce qu'il faut faire<input id="etit" value="${esc(ed.titre || "")}"></label><label>Tous les (jours)<input id="efr" type="number" min="1" max="730" value="${ed.frequence_jours || 90}"></label></div>
      <div class="row3"><label>Prochaine fois<input id="epro" type="date" value="${esc(ed.prochaine || czIso(new Date()))}"></label><label>Heure<input id="eh" type="time" value="${esc(ed.heure || "10:00")}"></label>
        <label>Intervenant<select id="eag"><option value="">Habituel du logement</option>${ag.map(a => `<option value="${a.id}" ${+ed.animatrice_id === +a.id ? "selected" : ""}>${esc(a.prenom + " " + a.nom)}</option>`).join("")}</select></label></div>
      <div class="row3"><label>Ou artisan extérieur<input id="epr" value="${esc(ed.prestataire || "")}" placeholder="Froid Service, 06 …"></label><label style="grid-column:span 2">Consignes<input id="eco" value="${esc(ed.consignes || "")}"></label></div>
      <div class="hint">La mission de maintenance est créée sept jours avant l'échéance, puis la date suivante est calculée.</div>
      <div style="display:flex;gap:8px"><button class="primary" id="es">Enregistrer</button><button class="btn ghost" id="ex">Annuler</button>${ed.id ? `<button class="btn ghost" id="ed" style="margin-left:auto">Arrêter cet entretien</button>` : ""}</div></div>` : "";
  const sugg = g ? czEntSuggestions() : [], kitsVides = logs.filter(l => !Object.values(L.kits[l.id] || {}).some(q => q > 0));
  const depart = g && (!A.length || (A.length && kitsVides.length) || A.some(a => !a.stock)) ? `<div class="card accent" style="display:grid;gap:8px"><b>Démarrer sans tout saisir</b>
      ${!A.length ? `<div>Créer la liste habituelle : ${CZ_LINGE_BASE.map(x => esc(x[0])).join(", ")}. <button class="primary" id="lbase">Créer la liste</button></div>` : ""}
      ${A.length && kitsVides.length ? `<div>${kitsVides.length} logement${kitsVides.length > 1 ? "s" : ""} sans kit : Keylia propose un kit d'après les pièces de chaque logement (chambres, salles de bain, cuisine). <button class="btn" id="lkitp">Proposer les kits</button></div>` : ""}
      ${A.length && !kitsVides.length && A.some(a => !a.stock) ? `<div>Stock conseillé : trois jeux par kit (un posé, un à la blanchisserie, un en réserve). <button class="btn" id="lstp">Calculer les stocks manquants</button></div>` : ""}</div>` : "";
  const suggC = sugg.length ? `<div class="card" style="display:grid;gap:8px"><div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center"><h3 style="margin:0">Entretiens conseillés</h3><button class="btn" id="esall">Tout programmer (${sugg.length})</button></div>
      <div class="hint">D'après les pièces de chaque logement. La première intervention est placée dans les deux à six semaines, à ajuster ensuite.</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px">${sugg.map((x, i) => `<button class="btn ghost" data-esg="${i}" style="padding:6px 10px">+ ${esc(x.logement)} · ${esc(x.titre)}</button>`).join("")}</div></div>` : "";
  return `${depart}${alerte.length ? `<div class="card accent"><b>Linge à surveiller :</b> ${alerte.map(a => `${esc(a.nom)} (${a.disponible} disponible${a.disponible > 1 ? "s" : ""} pour ${a.besoin} à poser dans les trois jours)`).join(" · ")}</div>` : ""}
    <div class="card" style="display:grid;gap:10px"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h2 style="margin:0">Linge</h2>
      ${g ? `<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="primary" id="lenv">Envoyer à la blanchisserie</button><button class="btn" id="lart">Ajouter un article</button></div>` : ""}</div>
      <div class="muted">Le linge sale est compté d'après les ménages faits et le kit de chaque logement : ${L.menages_depuis} ménage${L.menages_depuis > 1 ? "s" : ""} depuis le dernier envoi (${esc(czD(L.depuis))}). ${L.menages_3j} ménage${L.menages_3j > 1 ? "s" : ""} prévu${L.menages_3j > 1 ? "s" : ""} dans les trois jours.</div>
      <div id="lenvf" hidden class="row3"><label>Blanchisserie<input id="lbl" value="${esc((L.envois[0] || {}).blanchisserie || "")}" placeholder="Pressing Gueliz"></label><label>Note<input id="lno"></label><div style="align-self:end"><button class="primary" id="lgo">Confirmer l'envoi</button></div></div>
      <div id="lartf" hidden class="row3"><label>Article<input id="lan" placeholder="Drap housse 160"></label><label>Stock total<input id="las" type="number" min="0"></label><label>Prix de lavage (DH la pièce)<input id="lap" inputmode="decimal"></label><div><button class="primary" id="lags">Ajouter</button></div></div>
      <div class="scroll"><table class="tbl"><thead><tr><th>Article</th><th>Stock</th><th>Sale, à envoyer</th><th>À la blanchisserie</th><th>Disponible</th><th>À poser sous 3 jours</th><th>Lavage</th></tr></thead><tbody>
      ${A.map(a => `<tr><td>${esc(a.nom)}</td><td>${g ? `<input data-lst="${a.id}" type="number" min="0" value="${a.stock}" style="max-width:80px">` : a.stock}</td><td>${a.sale}</td><td>${a.blanchisserie}</td><td>${a.alerte ? `<span class="pill p-bad">${a.disponible}</span>` : `<b>${a.disponible}</b>`}</td><td>${a.besoin}</td><td>${czDH(a.prix_lavage)}</td></tr>`).join("") || `<tr><td colspan="7" class="muted">Ajoutez vos articles : draps, housses, taies, serviettes, tapis de bain, torchons.</td></tr>`}</tbody></table></div>
      ${g && A.length ? `<div><button class="btn ghost" id="lsto">Enregistrer les stocks</button></div>` : ""}
      ${enCours.map(e => `<div class="card" style="background:var(--pierre);display:grid;gap:8px"><b>Envoi du ${esc(czD(e.envoye_at))}${e.blanchisserie ? " · " + esc(e.blanchisserie) : ""}</b>
        <div class="row3" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">${Object.entries(e.lignes).map(([a, l]) => `<label>${esc(l.nom)} <span class="hint">envoyé ${l.envoye}</span><input data-rec="${e.id}" data-art="${a}" type="number" min="0" max="${l.envoye}" value="${l.envoye}"></label>`).join("")}</div>
        ${g ? `<div class="row3"><label>Montant de la facture (DH) <span class="hint">vide = calculé</span><input data-rcout="${e.id}" inputmode="decimal"></label><div style="align-self:end"><button class="primary" data-ret="${e.id}">Enregistrer le retour</button></div></div>` : ""}</div>`).join("")}
      ${faits.length ? `<details><summary>Envois revenus (${faits.length})</summary><table class="tbl"><thead><tr><th>Envoi</th><th>Retour</th><th>Pièces</th><th>Manquantes</th><th>Coût</th></tr></thead><tbody>
        ${faits.map(e => { const l = Object.values(e.lignes), env = l.reduce((n, x) => n + +x.envoye, 0), rec = l.reduce((n, x) => n + +x.recu, 0); return `<tr><td>${esc(czD(e.envoye_at))}</td><td>${esc(czD(e.retour_at))}</td><td>${env}</td><td>${env - rec ? `<span class="pill p-warn">${env - rec}</span>` : "0"}</td><td>${czDH(e.cout)}</td></tr>`; }).join("")}</tbody></table></details>` : ""}</div>
    ${A.length ? `<div class="card"><h3 style="margin:0 0 8px">Kit de linge par logement</h3><div class="hint" style="margin-bottom:8px">Ce qui est posé à chaque ménage. Il sert à compter le linge sale et le besoin des prochains jours.</div><div class="scroll"><table class="tbl"><thead><tr><th>Logement</th>${A.map(a => `<th>${esc(a.nom)}</th>`).join("")}</tr></thead><tbody>
      ${logs.map(l => `<tr><td>${esc(l.nom)}</td>${A.map(a => `<td>${g ? `<input data-kit="${l.id}" data-ka="${a.id}" type="number" min="0" max="99" value="${(L.kits[l.id] || {})[a.id] || 0}" style="max-width:64px">` : (L.kits[l.id] || {})[a.id] || 0}</td>`).join("")}</tr>`).join("")}</tbody></table></div>
      ${g ? `<button class="btn" id="lkit" style="margin-top:10px">Enregistrer les kits</button>` : ""}</div>` : ""}
    <div class="card" style="display:grid;gap:10px"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h2 style="margin:0">Entretien programmé</h2>${g ? `<button class="primary" id="enew">Programmer un entretien</button>` : ""}</div>
      <div class="muted">Climatisation, piscine, désinsectisation, chauffe-eau : chaque entretien revient seul dans les missions, à la bonne date, et apparaît chez le propriétaire une fois fait.</div></div>
    ${entForm}${suggC}
    <div class="card"><div class="scroll"><table class="tbl"><thead><tr><th>Logement</th><th>Entretien</th><th>Fréquence</th><th>Dernière fois</th><th>Intervention prévue</th><th>Suivante</th><th></th></tr></thead><tbody>
      ${I.entretiens.map(e => `<tr><td>${esc(e.logement)}</td><td>${esc(e.titre)}${e.prestataire ? `<div class="hint">${esc(e.prestataire)}</div>` : ""}</td><td>tous les ${e.frequence_jours} jours</td><td>${e.derniere_faite ? esc(czD(e.derniere_faite)) : "–"}</td>
        <td>${e.mission ? `${esc(czD(e.mission.date))} ${e.retard ? `<span class="pill p-bad">En retard</span>` : czSt(e.mission.statut)}` : "–"}</td><td>${esc(czD(e.prochaine))}</td><td>${g ? `<button class="btn" data-eed="${e.id}">Modifier</button>` : ""}</td></tr>`).join("") || `<tr><td colspan="7" class="muted">Aucun entretien programmé.</td></tr>`}</tbody></table></div></div>`;
}
function czBInt(){
  const I = CZ.int, sugg = czEntSuggestions();
  if ($("#lbase")) $("#lbase").onclick = async () => { try { for (const [nom, px] of CZ_LINGE_BASE) await czapi("linge_article_save", {nom, stock:0, prix_lavage:px}); toast("Liste créée : prix de lavage indicatifs, à ajuster."); czRe(); } catch(e) { toast(e.message); } };
  if ($("#lkitp")) $("#lkitp").onclick = async () => { const ids = Object.fromEntries(I.linge.articles.map(a => [a.nom, a.id]));
    try { for (const l of CZ.ini.logements.filter(l => l.actif && !Object.values(I.linge.kits[l.id] || {}).some(q => q > 0))) { const k = czKitPropose(l), kit = {}; for (const [n, q] of Object.entries(k)) if (ids[n]) kit[ids[n]] = q; await czapi("linge_kit_save", {logement_id:l.id, kit}); }
      toast("Kits proposés : vérifiez-les dans le tableau."); czRe(); } catch(e) { toast(e.message); } };
  if ($("#lstp")) $("#lstp").onclick = async () => { try { for (const a of I.linge.articles.filter(a => !a.stock)) { const tot = Object.values(I.linge.kits).reduce((s, k) => s + (k[a.id] || 0), 0); await czapi("linge_article_save", {id:a.id, nom:a.nom, stock:tot * 3, prix_lavage:a.prix_lavage}); } toast("Stocks calculés."); czRe(); } catch(e) { toast(e.message); } };
  const prog = (x, i) => czapi("entretien_save", {logement_id:x.logement_id, titre:x.titre, frequence_jours:x.frequence_jours, prochaine:czIso(new Date(Date.now() + (14 + (i % 5) * 7) * 864e5)), heure:"10:00"});
  document.querySelectorAll("[data-esg]").forEach(b => b.onclick = async () => { try { await prog(sugg[+b.dataset.esg], +b.dataset.esg); toast("Entretien programmé."); czRe(); } catch(e) { toast(e.message); } });
  if ($("#esall")) $("#esall").onclick = async () => { try { for (const [i, x] of sugg.entries()) await prog(x, i); toast(sugg.length + " entretiens programmés."); czRe(); } catch(e) { toast(e.message); } };
  const act = async (a, b, ok) => { try { const r = await czapi(a, b); toast(ok(r)); czRe(); } catch(e) { toast(e.message); } };
  if ($("#lenv")) $("#lenv").onclick = () => { $("#lenvf").hidden = false; };
  if ($("#lgo")) $("#lgo").onclick = () => act("linge_envoyer", {blanchisserie:$("#lbl").value, note:$("#lno").value}, () => "Envoi enregistré.");
  if ($("#lart")) $("#lart").onclick = () => { $("#lartf").hidden = false; $("#lan").focus(); };
  if ($("#lags")) $("#lags").onclick = () => act("linge_article_save", {nom:$("#lan").value, stock:$("#las").value, prix_lavage:$("#lap").value}, () => "Article ajouté.");
  if ($("#lsto")) $("#lsto").onclick = async () => { try { for (const i of document.querySelectorAll("[data-lst]")) { const a = I.linge.articles.find(x => x.id === +i.dataset.lst); if (+i.value !== a.stock) await czapi("linge_article_save", {id:a.id, nom:a.nom, stock:i.value, prix_lavage:a.prix_lavage}); } toast("Stocks enregistrés."); czRe(); } catch(e) { toast(e.message); } };
  if ($("#lkit")) $("#lkit").onclick = async () => { const K = {}; document.querySelectorAll("[data-kit]").forEach(i => { (K[i.dataset.kit] = K[i.dataset.kit] || {})[i.dataset.ka] = i.value; });
    try { for (const [lid, kit] of Object.entries(K)) await czapi("linge_kit_save", {logement_id:+lid, kit}); toast("Kits enregistrés."); czRe(); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-ret]").forEach(b => b.onclick = () => { const id = b.dataset.ret, recus = {}; document.querySelectorAll(`[data-rec="${id}"]`).forEach(i => recus[i.dataset.art] = i.value);
    act("linge_retour", {id:+id, recus, cout:($(`[data-rcout="${id}"]`) || {}).value || ""}, r => "Retour enregistré" + (Object.keys(r.pertes).length ? " : " + Object.entries(r.pertes).map(([n, q]) => q + " " + n).join(", ") + " manquant(s), retiré(s) du stock." : ", rien ne manque.")); });
  if ($("#enew")) $("#enew").onclick = () => { CZ.entEd = {}; czRe(); };
  document.querySelectorAll("[data-eed]").forEach(b => b.onclick = () => { CZ.entEd = Object.assign({}, I.entretiens.find(e => +e.id === +b.dataset.eed)); czRe(); });
  if ($("#emod")) $("#emod").onchange = () => { const m = I.modeles[+$("#emod").value]; if (m) { $("#etit").value = m[0]; $("#efr").value = m[1]; } };
  if ($("#ex")) $("#ex").onclick = () => { CZ.entEd = null; czRe(); };
  const body = sup => ({id:CZ.entEd.id || 0, logement_id:$("#elog").value, titre:$("#etit").value, frequence_jours:$("#efr").value, prochaine:$("#epro").value, heure:$("#eh").value, animatrice_id:$("#eag").value, prestataire:$("#epr").value, consignes:$("#eco").value, supprimer:sup});
  if ($("#es")) $("#es").onclick = async () => { try { const r = await czapi("entretien_save", body(false)); CZ.entEd = null; toast(r.missions_creees ? "Entretien programmé : mission créée." : "Entretien programmé."); czRe(); } catch(e) { toast(e.message); } };
  if ($("#ed")) $("#ed").onclick = async () => { if (!confirm("Arrêter cet entretien ? Les missions déjà créées restent.")) return; try { await czapi("entretien_save", body(true)); CZ.entEd = null; czRe(); } catch(e) { toast(e.message); } };
}

/* ---------- Propositions toutes prêtes : la conciergerie coche et ajuste au lieu de tout saisir ---------- */
const CZ_MODELES_LOG = {
  "Studio": [["Pièce principale", 3], ["Salle de bain", 2], ["Coin cuisine", 2], ["Entrée et clés", 1, "Boîte à clés refermée, code brouillé"]],
  "Appartement 2 chambres": [["Chambre principale", 2], ["Deuxième chambre", 2], ["Salle de bain", 2], ["Cuisine", 2, "Réfrigérateur vidé, poubelles sorties"], ["Salon", 2], ["Entrée et clés", 1, "Boîte à clés refermée, code brouillé"]],
  "Villa avec piscine": [["Chambre principale", 3], ["Chambres d'amis", 2], ["Salles de bain", 2], ["Cuisine", 2, "Réfrigérateur vidé, poubelles sorties"], ["Salon", 2], ["Terrasse et piscine", 2, "Transats alignés, serviettes de piscine posées"], ["Jardin", 1], ["Entrée et clés", 1, "Boîte à clés refermée, code brouillé"]],
  "Riad": [["Chambres", 3], ["Salles de bain", 2], ["Patio", 2, "Fontaine propre, coussins en place"], ["Terrasse", 2], ["Cuisine", 2, "Réfrigérateur vidé, poubelles sorties"], ["Entrée et clés", 1, "Porte verrouillée, clés rangées"]]};
// [français, anglais, darija, description, prix indicatif, coût indicatif, délai (h)] : à ajuster par chaque conciergerie.
const CZ_CATALOGUE = [
  ["Eau minérale, six bouteilles", "Mineral water, six bottles", "ماء معدني، ستة قراعي", "Posées au frais avant votre arrivée", 60, 25, 12],
  ["Panier de bienvenue", "Welcome basket", "سلة الترحيب", "Thé à la menthe, pâtisseries marocaines, fruits secs", 150, 60, 24],
  ["Corbeille de fruits de saison", "Seasonal fruit basket", "سلة ديال الفواكه ديال الموسم", "Fruits du marché, sur la table de la cuisine", 180, 70, 24],
  ["Petit-déjeuner du premier matin", "First-morning breakfast", "فطور الصباح الأول", "Pain, msemen, confiture, jus d'orange, café", 250, 110, 24],
  ["Courses selon votre liste", "Groceries from your list", "التقدية حسب اللائحة ديالك", "Hors prix des produits, réglés sur ticket", 200, 80, 24],
  ["Transfert depuis l'aéroport", "Airport transfer", "النقل من المطار", "Chauffeur à l'arrivée, suivi de votre vol", 450, 300, 24],
  ["Ménage pendant le séjour", "Mid-stay cleaning", "التنظيف وسط الإقامة", "Ménage complet et linge changé", 300, 150, 24],
  ["Arrivée tardive", "Late check-in", "الوصول متأخر", "Accueil en personne après 22 h", 150, 80, 12],
  ["Kit bébé", "Baby kit", "لوازم البيبي", "Lit parapluie, chaise haute, baignoire", 150, 20, 48],
  ["Chef à domicile", "Private chef", "طباخ فالدار", "Dîner marocain pour quatre personnes, courses comprises", 1200, 800, 48],
  ["Hammam et massage à domicile", "Hammam and massage at home", "حمام و مساج فالدار", "Une heure par personne, praticienne diplômée", 600, 420, 48]];
function czVCat(){
  const deja = new Set(CZ.srv.services.map(s => s.nom));
  return `<div class="card accent" style="display:grid;gap:10px;margin-top:12px"><h3 style="margin:0">Catalogue des services</h3><div class="hint">Cochez ceux que vous proposez. Les prix sont indicatifs : ajustez-les à votre ville et à vos fournisseurs. Les noms sont déjà traduits en anglais et en darija.</div>
    <div class="scroll"><table class="tbl"><thead><tr><th></th><th>Service</th><th>Prix voyageur (DH)</th><th>Votre coût (DH)</th></tr></thead><tbody>
    ${CZ_CATALOGUE.map((x, i) => `<tr${deja.has(x[0]) ? ' style="opacity:.5"' : ""}><td><input type="checkbox" data-cat="${i}" ${deja.has(x[0]) ? "disabled" : ""}></td><td><b>${esc(x[0])}</b><div class="hint">${esc(x[3])}${deja.has(x[0]) ? " · déjà proposé" : ""}</div></td>
      <td><input data-catp="${i}" type="number" min="0" value="${x[4]}" style="max-width:100px"></td><td><input data-catc="${i}" type="number" min="0" value="${x[5]}" style="max-width:100px"></td></tr>`).join("")}</tbody></table></div>
    <div style="display:flex;gap:8px"><button class="primary" id="catok">Ajouter la sélection</button><button class="btn ghost" id="catx">Fermer</button></div></div>`;
}
const CZ_LINGE_BASE = [["Drap double", 12], ["Housse de couette", 15], ["Taie d'oreiller", 4], ["Serviette de bain", 6], ["Serviette de main", 4], ["Tapis de bain", 5], ["Torchon", 3]];
/** Kit proposé d'après les pièces de la check-list : une parure par chambre, une serviette de bain et de main par personne (deux par chambre), un tapis par salle de bain, deux torchons par cuisine. */
function czKitPropose(l){
  const P = (l.checklist || []).map(p => String(p.piece).toLowerCase()), n = re => P.filter(p => re.test(p)).reduce((s, p) => s + (/s$|amis|chambres/.test(p) && !/principale/.test(p) ? 2 : 1), 0);
  const ch = Math.max(1, n(/chambre|pièce principale/)), sdb = Math.max(1, n(/bain|douche/)), cu = P.some(p => /cuisine/.test(p)) ? 1 : 0;
  return {"Drap double":ch, "Housse de couette":ch, "Taie d'oreiller":ch * 2, "Serviette de bain":ch * 2, "Serviette de main":ch * 2, "Tapis de bain":sdb, "Torchon":cu * 2};
}
const CZ_ENT_SUGG = [["Entretien de la climatisation", 90, () => true], ["Désinsectisation", 90, () => true], ["Détartrage du chauffe-eau", 365, () => true], ["Contrôle de l'extincteur", 365, () => true],
  ["Nettoyage de la piscine", 7, P => P.some(p => /piscine/.test(p))], ["Jardin et arrosage", 14, P => P.some(p => /jardin/.test(p))]];
function czEntSuggestions(){
  const I = CZ.int, fait = new Set(I.entretiens.map(e => e.logement_id + "|" + e.titre)), out = [];
  CZ.ini.logements.filter(l => l.actif).forEach(l => { const P = (l.checklist || []).map(p => String(p.piece).toLowerCase());
    CZ_ENT_SUGG.forEach(([t, f, ok]) => { if (ok(P) && !fait.has(l.id + "|" + t)) out.push({logement_id:l.id, logement:l.nom, titre:t, frequence_jours:f}); }); });
  return out;
}

/* ---------- Nouveau logement en trois écrans ---------- */
const CZ_VILLES = ["Marrakech", "Casablanca", "Rabat", "Tanger", "Agadir", "Essaouira", "Fès"];
const CZ_TYPES_ICO = {"Studio":"1 pièce, salle d'eau, coin cuisine", "Appartement 2 chambres":"2 chambres, salon, cuisine, salle de bain", "Villa avec piscine":"Chambres, salons, jardin et piscine", "Riad":"Chambres autour du patio, terrasse"};
function czVWiz(){
  const W = CZ.wiz, d = W.d, P = CZ.ini.proprietaires.filter(p => +p.actif !== 0), et = ["Le logement", "Son type", "L'accès"];
  const pas = `<div class="wz-pas">${et.map((t, i) => `<span class="${i + 1 === W.etape ? "on" : i + 1 < W.etape ? "ok" : ""}"><b>${i + 1 < W.etape ? "✓" : i + 1}</b>${t}</span>`).join("")}</div>`;
  let corps = "";
  if (W.etape === 1) corps = `<label>Nom du logement<input id="wn" value="${esc(d.nom || "")}" placeholder="Villa des Oliviers" autofocus></label>
    <div class="row3"><label>Ville<input id="wv" list="wvl" value="${esc(d.ville || "")}"><datalist id="wvl">${CZ_VILLES.map(v => `<option value="${v}">`).join("")}</datalist></label><label style="grid-column:span 2">Adresse ou quartier<input id="wa" value="${esc(d.adresse || "")}" placeholder="Route de l'Ourika, km 7"></label></div>
    <div class="row3"><label>Propriétaire<select id="wp"><option value="">Choisir…</option>${P.map(p => `<option value="${p.id}" ${+d.proprietaire_id === +p.id ? "selected" : ""}>${esc(p.nom)}</option>`).join("")}<option value="nouveau" ${d.proprietaire_id === "nouveau" ? "selected" : ""}>+ Nouveau propriétaire</option></select></label>
      <label id="wpnl" style="grid-column:span 2" ${d.proprietaire_id === "nouveau" ? "" : "hidden"}>Nom du nouveau propriétaire<input id="wpn" value="${esc(d.prop_nom || "")}" placeholder="M. et Mme Alami"></label></div>
    <label>Lien du calendrier Airbnb ou Booking <span class="hint">· facultatif, les réservations arriveront seules</span><input id="wi" value="${esc(d.ical || "")}" placeholder="https://www.airbnb.fr/calendar/ical/…"></label>`;
  if (W.etape === 2) corps = `<div class="wz-types">${Object.keys(CZ_MODELES_LOG).map(t => `<button type="button" class="wz-type ${d.type === t ? "on" : ""}" data-wt="${esc(t)}"><b>${esc(t)}</b><span>${esc(CZ_TYPES_ICO[t])}</span><small>${CZ_MODELES_LOG[t].length} pièces à photographier</small></button>`).join("")}</div>
    <div class="hint">Keylia prépare la check-list du ménage, le kit de linge et les entretiens conseillés. Tout se modifie ensuite.</div>
    <label style="display:flex;gap:10px;align-items:center;font-weight:400"><input type="checkbox" id="we" style="width:auto" ${d.entretiens ? "checked" : ""}> Programmer les entretiens conseillés (climatisation, désinsectisation, chauffe-eau, extincteur${/piscine/i.test(d.type) ? ", piscine" : ""})</label>`;
  if (W.etape === 3) corps = `<div class="row3"><label>Code de la boîte à clés <span class="hint">· chiffré</span><input id="wc" value="${esc(d.code || "")}" autocomplete="off"></label><label>Wi-Fi : nom du réseau<input id="wwn" value="${esc(d.wifi_nom || "")}"></label><label>Wi-Fi : mot de passe<input id="wwm" value="${esc(d.wifi_mdp || "")}" autocomplete="off"></label></div>
    <label>Position exacte <span class="hint">· collez le lien Google Maps du logement, ou des coordonnées</span><input id="wpos" value="${esc(d.position || "")}" placeholder="https://www.google.com/maps/place/…"></label>
    <label>Repère pour trouver la porte<input id="wr" value="${esc(d.repere || "")}" placeholder="Portail blanc après la pharmacie, deuxième ruelle à gauche"></label>
    <div class="hint">Les photos du chemin s'ajoutent ensuite dans la fiche du logement, ou depuis le téléphone sur place.</div>`;
  if (W.etape === 4) return `<div class="card wz"><div class="wz-ok">✓</div><h2 style="margin:0">${esc(d.nom)} est prêt.</h2>
    <div class="muted">${W.bilan.map(esc).join(" · ")}</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px"><button class="primary" data-wfin="fiche">Ajouter les photos du chemin</button><button class="btn" data-wfin="autre">Créer un autre logement</button><button class="btn ghost" data-wfin="liste">Voir mes logements</button></div></div>`;
  return `<div class="card wz"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap"><h2 style="margin:0">Nouveau logement</h2><button class="btn ghost" id="wx">Annuler</button></div>${pas}
    <div class="wz-c">${corps}</div><div style="display:flex;gap:8px;justify-content:space-between;margin-top:6px">${W.etape > 1 ? `<button class="btn ghost" id="wprev">‹ Retour</button>` : "<span></span>"}<button class="primary" id="wnext">${W.etape === 3 ? "Créer le logement" : "Continuer ›"}</button></div></div>`;
}
function czBWiz(){
  const W = CZ.wiz, d = W.d;
  const lire = () => { const v = id => ($(id) || {}).value;
    if (W.etape === 1) Object.assign(d, {nom:v("#wn").trim(), ville:v("#wv").trim(), adresse:v("#wa").trim(), proprietaire_id:v("#wp"), prop_nom:(v("#wpn") || "").trim(), ical:v("#wi").trim()});
    if (W.etape === 2) d.entretiens = $("#we").checked;
    if (W.etape === 3) Object.assign(d, {code:v("#wc"), wifi_nom:v("#wwn"), wifi_mdp:v("#wwm"), position:v("#wpos").trim(), repere:v("#wr")}); };
  if ($("#wx")) $("#wx").onclick = () => { CZ.wiz = null; czRe(); };
  if ($("#wp")) $("#wp").onchange = () => { $("#wpnl").hidden = $("#wp").value !== "nouveau"; if (!$("#wpnl").hidden) $("#wpn").focus(); };
  document.querySelectorAll("[data-wt]").forEach(b => b.onclick = () => { lire(); d.type = b.dataset.wt; czRe(); });
  if ($("#wprev")) $("#wprev").onclick = () => { lire(); W.etape--; czRe(); };
  if ($("#wnext")) $("#wnext").onclick = async () => { lire();
    if (W.etape === 1) { if (!d.nom) return toast("Donnez un nom au logement."); if (!d.ville) return toast("Indiquez la ville."); if (d.proprietaire_id === "nouveau" && !d.prop_nom) return toast("Indiquez le nom du propriétaire."); W.etape = 2; return czRe(); }
    if (W.etape === 2) { W.etape = 3; return czRe(); }
    const b = $("#wnext"); b.disabled = true; b.textContent = "Création…"; const bilan = [];
    try {
      let pid = +d.proprietaire_id || 0;
      if (d.proprietaire_id === "nouveau") { pid = (await czapi("proprietaire_save", {id:0, nom:d.prop_nom, email:"", telephone:"", plafond:0, actif:true})).id; d.proprietaire_id = String(pid); bilan.push("propriétaire créé"); }
      const chk = CZ_MODELES_LOG[d.type].map(([piece, photos, consignes]) => ({piece, photos, consignes:consignes || ""}));
      const r = await czapi("logement_save", {id:0, nom:d.nom, ville:d.ville, adresse:d.adresse, proprietaire_id:pid, agent_id:0, kit_linge:"", duree_menage:/Villa/.test(d.type) ? 240 : /Riad/.test(d.type) ? 180 : /Studio/.test(d.type) ? 75 : 120,
        acces:"", code:d.code, position:d.position, livret:{repere:d.repere, parking:"", serrure:d.code ? "Boîte à clés : composer le code, puis refermer et brouiller les chiffres" : "", wifi_nom:d.wifi_nom, wifi_mdp:d.wifi_mdp, urgence:"", depart:"Remettre les clés à leur place, éteindre la climatisation, fermer les fenêtres", etapes:[]},
        ical:d.ical, checklist:chk, notes:"", actif:true});
      const lid = r.id; bilan.push(chk.length + " pièces à photographier"); if (Array.isArray(r.sync)) bilan.push(r.sync[0] + " réservation(s) importée(s)"); else if (d.ical) bilan.push("calendrier relié");
      const I = await czapi("intendance"), ids = Object.fromEntries(I.linge.articles.map(a => [a.nom, a.id]));
      if (I.linge.articles.length) { const k = czKitPropose({checklist:chk}), kit = {}; for (const [n, q] of Object.entries(k)) if (ids[n]) kit[ids[n]] = q; await czapi("linge_kit_save", {logement_id:lid, kit}); bilan.push("kit de linge proposé"); }
      if (d.entretiens) { const P = chk.map(c => c.piece.toLowerCase()), S = CZ_ENT_SUGG.filter(([, , ok]) => ok(P));
        for (const [i, [titre, f]] of S.entries()) await czapi("entretien_save", {logement_id:lid, titre, frequence_jours:f, prochaine:czIso(new Date(Date.now() + (14 + i * 7) * 864e5)), heure:"10:00"}); bilan.push(S.length + " entretiens programmés"); }
      if (d.code) bilan.push("code chiffré"); if (d.position) bilan.push("position enregistrée");
      W.etape = 4; W.bilan = bilan; W.lid = lid; CZ.ini = null; czRe();
    } catch(e) { toast(e.message); b.disabled = false; b.textContent = "Créer le logement"; }
  };
  document.querySelectorAll("[data-wfin]").forEach(b => b.onclick = async () => { const k = b.dataset.wfin, lid = W.lid; CZ.wiz = null;
    if (k === "autre") CZ.wiz = {etape:1, d:{ville:d.ville, type:d.type, entretiens:true}};
    if (k === "fiche") { try { CZ.ini = await czapi("init"); const l = CZ.ini.logements.find(x => +x.id === +lid); if (l) CZ.ed = Object.assign({}, (await czapi("logement_get", null, "id=" + lid)).logement); } catch(e) { toast(e.message); } }
    czRe(); });
}

/* Textes types du livret : français puis anglais, à adapter. */
const CZ_LIVRET_TYPES = {
  "#lser": "Boîte à clés à côté de la porte : composez le code, ouvrez le volet, prenez les clés, puis refermez et brouillez les chiffres.\nKey box next to the door: enter the code, open the cover, take the keys, then close it and scramble the digits.",
  "#ldep": "Avant 11 h : laissez les clés dans la boîte, éteignez la climatisation et les lumières, fermez les fenêtres et sortez les poubelles.\nBefore 11 am: leave the keys in the box, switch off the air conditioning and lights, close the windows and take out the rubbish.",
  "#lreg": "Logement non-fumeur. Pas de fête ni de musique forte après 22 h, par respect pour les voisins. Les personnes qui n'ont pas rempli leur fiche d'arrivée ne peuvent pas loger sur place. Signalez toute casse depuis cette page, sans attendre le départ.\nNon-smoking. No parties or loud music after 10 pm. Only guests who filled in their arrival form may stay overnight. Please report any damage from this page.",
  "#lpark": "Stationnement possible dans la rue devant le logement. Ne laissez rien de visible dans la voiture.\nParking is available in the street in front of the property. Leave nothing visible in the car."};

/* ---------- Paiement de l'équipe : simple, pas une comptabilité ---------- */
function czVPaie(){
  const P = CZ.pa, T = P.tarifs, types = CZ.ini.types || {}, sansT = !Object.values(T.types).some(v => v > 0);
  const mois = new Date(P.mois + "-15").toLocaleDateString("fr-FR", {month:"long", year:"numeric"});
  const tar = `<details class="card" ${sansT ? "open" : ""}><summary><b>Tarifs</b> <span class="hint">${sansT ? "à renseigner pour calculer ce que vous devez" : "ce que vous versez par mission faite"}</span></summary>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:10px">${Object.entries(types).map(([k, l]) => `<label>${esc(l)} (DH)<input data-pt="${k}" inputmode="decimal" value="${T.types[k] || ""}"></label>`).join("")}</div>
      <div style="margin-top:10px"><b>Ménage, selon le logement</b> <span class="hint">· vide = tarif ci-dessus</span></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-top:6px">${CZ.ini.logements.filter(l => l.actif).map(l => `<label>${esc(l.nom)}<input data-pl="${l.id}" inputmode="decimal" value="${T.logements[l.id] || ""}" placeholder="${T.types.menage || ""}"></label>`).join("")}</div>
      <label style="display:flex;gap:10px;align-items:center;font-weight:400;margin-top:12px"><input type="checkbox" id="psig" style="width:auto" ${P.signature ? "checked" : ""}> Faire signer chaque paiement (sur votre téléphone, ou par l'intervenant dans son appli)</label>
      <div style="margin-top:12px"><button class="primary" id="pts">Enregistrer</button></div></details>`;
  const fiche = x => { const det = (CZ.paDet || {})[x.id], R = x.regle;
    const etat = R ? `<span class="pill p-ok">Payé le ${esc(czD(R.le))}</span>${R.signature ? ` <a href="${esc(R.signature)}" target="_blank" rel="noopener" title="Signature"><img src="${esc(R.signature)}" alt="Signature" class="pa-sig"></a>` : R.a_signer ? ` <span class="pill p-todo">Signature attendue dans son appli</span>` : ""}` : "";
    return `<div class="card pa" style="display:grid;gap:8px"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline"><h3 style="margin:0">${esc(x.nom)}</h3><b style="font-size:26px;font-family:var(--serif);font-weight:400">${czDH(x.total)}</b></div>
      <div class="hint">${Object.entries(x.par_type).map(([k, v]) => { const l = (types[k] || k).toLowerCase(); return `${v.n} ${esc(v.n > 1 && !/s$/.test(l) ? l.replace(/^(\S+)/, "$1s") : l)}`; }).join(" · ") || "aucune mission faite"}${x.ajustements.map(a => ` · ${esc(a.libelle)} ${a.montant > 0 ? "+" : ""}${czDH(a.montant)}${R ? "" : ` <a href="#" data-pad="${x.id}:${a.id}" title="Retirer">×</a>`}`).join("")}${x.sans_tarif ? ` · <span style="color:#9A6B1A">${x.sans_tarif} sans tarif</span>` : ""}</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">${R ? etat + ` <a href="#" data-pan="${x.id}" class="hint">annuler</a>` : x.total > 0 ? `<button class="primary" data-pay="${x.id}">Payé</button>` : `<span class="hint">Rien à verser ce mois-ci.</span>`}
        ${R ? "" : `<button class="btn ghost" data-paj="${x.id}">Prime ou avance</button>`}<button class="btn ghost" data-pdt="${x.id}">${det ? "Masquer" : "Détail"}</button>${x.telephone ? `<button class="btn ghost" data-pwa="${x.id}">WhatsApp</button>` : ""}</div>
      ${det ? `<table class="tbl"><tbody>${x.missions.map(m => `<tr><td>${esc(czD(m.date))}</td><td>${esc(m.logement)}</td><td>${esc(types[m.type] || m.type)}</td><td>${m.montant ? czDH(m.montant) : `<span class="hint">sans tarif</span>`}</td></tr>`).join("")}</tbody></table>` : ""}</div>`; };
  return `<div class="card" style="display:flex;flex-direction:row;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;text-align:left"><div><div class="hint">Reste à payer · ${esc(mois)}</div><b style="font-size:30px;font-family:var(--serif);font-weight:400">${czDH(P.reste)}</b></div><input type="month" id="pam" value="${P.mois}" style="width:auto"></div>
    ${P.personnes.map(fiche).join("") || `<div class="card muted">Aucune mission faite par l'équipe en ${esc(mois)}.</div>`}${tar}`;
}
function czSignature(titre, sous, autre){
  return new Promise(ok => { const o = document.createElement("div"); o.className = "sg-ov";
    o.innerHTML = `<div class="sg"><b>${esc(titre)}</b><div class="hint">${esc(sous)}</div><canvas width="600" height="260"></canvas><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="primary" data-ok>Valider la signature</button><button class="btn ghost" data-eff>Effacer</button>${autre ? `<button class="btn ghost" data-aut>${esc(autre)}</button>` : ""}<button class="btn ghost" data-x>Annuler</button></div></div>`;
    document.body.appendChild(o); const c = o.querySelector("canvas"), g = c.getContext("2d"); g.lineWidth = 3; g.lineCap = "round"; g.strokeStyle = "#2C2F34"; let on = false, vide = true;
    const pt = e => { const r = c.getBoundingClientRect(), t = e.touches ? e.touches[0] : e; return [(t.clientX - r.left) * c.width / r.width, (t.clientY - r.top) * c.height / r.height]; };
    const deb = e => { e.preventDefault(); on = true; g.beginPath(); g.moveTo(...pt(e)); }, mv = e => { if (!on) return; e.preventDefault(); g.lineTo(...pt(e)); g.stroke(); vide = false; }, fin = () => on = false;
    c.addEventListener("pointerdown", deb); c.addEventListener("pointermove", mv); addEventListener("pointerup", fin);
    const ferme = v => { o.remove(); ok(v); };
    o.querySelector("[data-eff]").onclick = () => { g.clearRect(0, 0, c.width, c.height); vide = true; };
    o.querySelector("[data-ok]").onclick = () => { if (vide) return toast("Signez dans le cadre."); ferme({signature:c.toDataURL("image/png")}); };
    if (autre) o.querySelector("[data-aut]").onclick = () => ferme({plus_tard:true});
    o.querySelector("[data-x]").onclick = () => ferme(null); });
}
function czBPaie(){
  const P = CZ.pa, re = () => czRe(), go_ = async (a, b, msg) => { try { await czapi(a, b); if (msg) toast(msg); re(); } catch(e) { toast(e.message); } };
  $("#pam").onchange = () => { if ($("#pam").value) { CZ.paM = $("#pam").value; re(); } };
  $("#pts").onclick = () => { const types = {}, logements = {}; document.querySelectorAll("[data-pt]").forEach(i => types[i.dataset.pt] = i.value); document.querySelectorAll("[data-pl]").forEach(i => logements[i.dataset.pl] = i.value);
    go_("paie_tarifs", {types, logements, signature:$("#psig").checked}, "Enregistré."); };
  document.querySelectorAll("[data-paj]").forEach(b => b.onclick = () => { const i = b.dataset.paj, lib = prompt("Prime ou avance : libellé", "Prime"); if (!lib) return; const mt = prompt("Montant en DH (négatif pour une avance, par exemple -200)", ""); if (!mt) return;
    go_("paie_ajust", {animatrice_id:+i, mois:P.mois, libelle:lib, montant:mt}); });
  document.querySelectorAll("[data-pad]").forEach(b => b.onclick = e => { e.preventDefault(); const [a, id] = b.dataset.pad.split(":"); go_("paie_ajust", {animatrice_id:+a, mois:P.mois, id:+id, supprimer:true}); });
  document.querySelectorAll("[data-pay]").forEach(b => b.onclick = async () => { const x = P.personnes.find(p => p.id === +b.dataset.pay); let s = {};
    if (P.signature) { s = await czSignature(`${x.nom} reçoit ${czDH(x.total)}`, "Signature de l'intervenant, au doigt", "Signera dans son appli"); if (!s) return; }
    go_("paie_regler", {animatrice_id:x.id, mois:P.mois, moyen:"especes", signature:s.signature || "", dans_appli:!!s.plus_tard}, s.plus_tard ? "Payé : la signature lui est demandée dans son appli." : "Payé."); });
  document.querySelectorAll("[data-pan]").forEach(b => b.onclick = e => { e.preventDefault(); if (confirm("Annuler ce paiement ?")) go_("paie_regler", {animatrice_id:+b.dataset.pan, mois:P.mois, annuler:true}); });
  document.querySelectorAll("[data-pdt]").forEach(b => b.onclick = () => { CZ.paDet = CZ.paDet || {}; CZ.paDet[b.dataset.pdt] = !CZ.paDet[b.dataset.pdt]; re(); });
  const mois = new Date(P.mois + "-15").toLocaleDateString("fr-FR", {month:"long", year:"numeric"}), types = CZ.ini.types || {};
  document.querySelectorAll("[data-pwa]").forEach(b => b.onclick = () => { const x = P.personnes.find(p => p.id === +b.dataset.pwa), n = String(x.telephone).replace(/\D/g, "").replace(/^0/, "212");
    const txt = `Bonjour ${x.nom.split(" ")[0]},\n${mois} : ${Object.entries(x.par_type).map(([k, v]) => v.n + " " + (types[k] || k).toLowerCase()).join(", ")}${x.ajustements.map(a => `, ${a.libelle} ${a.montant > 0 ? "+" : ""}${a.montant} DH`).join("")}. Total : ${czDH(x.total)}.`;
    window.open("https://wa.me/" + n + "?text=" + encodeURIComponent(txt), "_blank", "noopener"); });
}
