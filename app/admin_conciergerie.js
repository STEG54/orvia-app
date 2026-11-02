// ORVIA : administration de la conciergerie (Aujourd'hui, Logements, Réservations, Missions, Signalements, Propriétaires).
const CZ = { ini:null, jour:null, logs:null, ed:null, res:null, resF:{ from:"", to:"", logement:"" }, det:null, mis:null, misF:{ from:"", to:"" }, mdet:null, inc:null, incS:"ouvert", prop:null, lien:null, decl:null, rap:null, rapF:null, plan:null, planD:"", avis:null, srv:null, srvEd:null };
const CZ_SUBS = [["cz_jour","Aujourd'hui"],["cz_planning","Planning"],["cz_reservations","Réservations"],["cz_missions","Missions"],["cz_logements","Logements"],["cz_incidents","Signalements"],["cz_proprietaires","Propriétaires"],["cz_rapports","Rapports"],["cz_avis","Avis"],["cz_services","Services"]];
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
  if (G.sub === "cz_jour") CZ.jour = await czapi("aujourdhui");
  if (G.sub === "cz_reservations") CZ.res = (await czapi("reservations", null, `from=${CZ.resF.from}&to=${CZ.resF.to}&logement=${CZ.resF.logement}`)).reservations;
  if (G.sub === "cz_missions") CZ.mis = (await czapi("missions", null, `from=${CZ.misF.from}&to=${CZ.misF.to}`)).missions;
  if (G.sub === "cz_incidents") CZ.inc = (await czapi("incidents", null, "statut=" + CZ.incS)).incidents;
  if (G.sub === "cz_logements" || G.sub === "cz_proprietaires") CZ.ini = await czapi("init");
}
function czView(){
  const v = {cz_jour:czVJour, cz_logements:czVLogements, cz_reservations:czVRes, cz_missions:czVMis, cz_incidents:czVInc, cz_proprietaires:czVProp, cz_planning:czVPlan, cz_rapports:czVRap, cz_avis:czVAvis, cz_services:czVSrv}[G.sub];
  return v ? v() : "";
}
function czBind(){
  const b = {cz_jour:czBJour, cz_logements:czBLogements, cz_reservations:czBRes, cz_missions:czBMis, cz_incidents:czBInc, cz_proprietaires:czBProp, cz_planning:czBPlan, cz_rapports:czBRap, cz_avis:czBAvis, cz_services:czBSrv}[G.sub];
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
  return `${qs ? `<div class="card accent"><h2>Questions des voyageurs à traiter</h2><ul>${qs}</ul></div>` : ""}<div class="card"><h2>Arrivées</h2>${arr || `<div class="muted">Aucune arrivée aujourd'hui ni demain.</div>`}</div>
    <div class="card"><h2>Missions du jour</h2>${mis ? `<div class="scroll"><table class="tbl"><thead><tr><th>Heure</th><th>Logement</th><th>Type</th><th>Équipe</th><th>État</th></tr></thead><tbody>${mis}</tbody></table></div>` : `<div class="muted">Aucune mission aujourd'hui.</div>`}</div>
    <div class="card"><h2>Signalements ouverts</h2>${inc ? `<ul>${inc}</ul><button class="btn" data-gs="cz_incidents">Tous les signalements</button>` : `<div class="muted">Aucun signalement ouvert.</div>`}</div>`;
}
function czBJour(){
  document.querySelectorAll("[data-lien]").forEach(b => b.onclick = () => czCopier(b.dataset.lien, "Lien copié : envoyez-le au voyageur par la messagerie de la plateforme."));
  document.querySelectorAll("[data-code]").forEach(b => b.onclick = () => czCode(+b.dataset.code));
  document.querySelectorAll("[data-det]").forEach(b => b.onclick = () => czDetail(+b.dataset.det));
  document.querySelectorAll("[data-mis]").forEach(b => b.onclick = () => czMission(+b.dataset.mis));
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
    ${CZ.ini.gerer && m.statut !== "faite" ? `<button class="btn" id="mwa">Envoyer à l'agent (WhatsApp)</button>` : ""}${CZ.ini.gerer && m.statut === "faite" ? `<button class="btn ghost" id="mrep">Demander de reprendre</button>` : ""}</div>
    ${m.pieces.map(p => `<div class="card"><div style="display:flex;justify-content:space-between"><b>${esc(p.piece)}</b>${p.ok ? `<span class="pill p-ok">Validée</span>` : `<span class="pill">À faire</span>`}</div>${p.consignes ? `<div class="hint">${esc(p.consignes)}</div>` : ""}
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">${p.urls.map((u, i) => `<a href="${esc(p.grandes[i])}" target="_blank" rel="noopener"><img src="${esc(u)}" alt="Photo ${esc(p.piece)}" style="width:110px;height:110px;object-fit:cover"></a>`).join("") || `<span class="hint">Aucune photo (${p.photos} demandée(s))</span>`}</div></div>`).join("")}`;
}
function czBMisDet(){
  $("#mback").onclick = () => { CZ.mdet = null; czRe(); };
  if ($("#mwa")) $("#mwa").onclick = () => czWaMission(CZ.mdet.id);
  if ($("#mrep")) $("#mrep").onclick = async () => { const n = prompt("Ce qui doit être repris :", ""); if (n === null) return; try { await czapi("mission_reprendre", {id:CZ.mdet.id, note:n}); czMission(CZ.mdet.id); } catch(e) { toast(e.message); } };
}

/* ---------- Logements ---------- */
function czVLogements(){
  if (CZ.ed) return czVLogEd();
  const P = Object.fromEntries(CZ.ini.proprietaires.map(p => [p.id, p.nom])), R = CZ.ini.reglages;
  return `<div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><h2>Logements</h2>${CZ.ini.gerer ? `<button class="primary" id="lnew">Nouveau logement</button>` : ""}</div>
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
  if (CZ.ed) return czBLogEd();
  if ($("#lnew")) $("#lnew").onclick = () => { CZ.ed = {id:0, nom:"", ville:"", adresse:"", proprietaire_id:null, agent_id:null, acces:"", code:"", kit_linge:"", duree_menage:120, ical:[], notes:"", actif:1, checklist:[{piece:"Chambre",photos:2},{piece:"Salle de bain",photos:2},{piece:"Cuisine",photos:2},{piece:"Salon",photos:2}]}; czRe(); };
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
    <h3>Check-list du ménage</h3><div id="lchk">${l.checklist.map((p, i) => czChkRow(p, i)).join("")}</div><button class="btn ghost" id="laddp">Ajouter une pièce</button>
    <label>Notes internes<textarea id="lno" rows="2">${esc(l.notes || "")}</textarea></label>
    ${l.id ? `<label class="chk"><input type="checkbox" id="lact" ${l.actif ? "checked" : ""}> Logement actif</label>` : ""}
    <button class="primary" id="lsave">Enregistrer</button></div>`;
}
const czChkRow = (p, i) => `<div class="chkrow" style="display:grid;grid-template-columns:2fr 3fr 90px 40px;gap:8px;margin-bottom:6px"><input data-cp="${i}" value="${esc(p.piece)}" placeholder="Pièce"><input data-cc="${i}" value="${esc(p.consignes || "")}" placeholder="Consignes (facultatif)"><input data-cn="${i}" type="number" min="0" max="10" value="${p.photos}" title="Photos demandées"><button class="btn ghost" data-cdel="${i}" title="Retirer">×</button></div>`;
function czLireChk(){ return [...document.querySelectorAll("[data-cp]")].map(x => { const i = x.dataset.cp; return {piece:x.value.trim(), consignes:$(`[data-cc="${i}"]`).value.trim(), photos:+$(`[data-cn="${i}"]`).value}; }).filter(p => p.piece); }
function czBLogEd(){
  $("#lback").onclick = () => { CZ.ed = null; czRe(); };
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
  const P = CZ.ini.proprietaires, n = id => CZ.ini.logements.filter(l => +l.proprietaire_id === +id).length;
  return `${CZ.lien ? `<div class="card accent"><h2>Lien de ${esc(CZ.lien.nom)}</h2><div class="muted">Affiché une seule fois. Transmettez-le au propriétaire : il ouvre son espace sans mot de passe. Un nouveau lien annule le précédent.</div>
      <input readonly value="${esc(CZ.lien.url)}" id="plien" style="width:100%"><button class="primary" id="pcop">Copier le lien</button></div>` : ""}
    <div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><h2>Propriétaires</h2>${CZ.ini.gerer ? `<button class="primary" id="pnew">Nouveau propriétaire</button>` : ""}</div>
    ${P.length ? `<div class="scroll"><table class="tbl"><thead><tr><th>Nom</th><th>Contact</th><th>Logements</th><th>Espace</th><th></th></tr></thead><tbody>
    ${P.map(p => `<tr><td>${esc(p.nom)}</td><td>${esc(p.email)}<div class="hint">${esc(p.telephone)}</div></td><td>${n(p.id)}</td><td>${+p.lien ? `<span class="pill p-ok">Lien actif</span>` : `<span class="pill">Pas de lien</span>`}</td>
      <td style="white-space:nowrap">${CZ.ini.gerer ? `<button class="btn" data-ped="${p.id}">Modifier</button> <button class="btn ghost" data-plink="${p.id}">${+p.lien ? "Nouveau lien" : "Créer le lien"}</button>` : ""}</td></tr>`).join("")}</tbody></table></div>` : `<div class="muted">Aucun propriétaire. Créez-le, rattachez-lui ses logements, puis envoyez-lui le lien de son espace.</div>`}</div>`;
}
function czBProp(){
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
  .pl-g{position:relative;height:22px;margin-left:160px;font-size:.72rem;color:#888}.pl-g span{position:absolute;transform:translateX(-50%)}.pl-b{position:absolute;top:2px;height:26px;border:1px solid #111;background:#fff;font-size:.74rem;padding:0 4px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;cursor:pointer;text-align:left}
  .pl-menage{background:#111;color:#fff}.pl-accueil,.pl-transfert{background:#e9e4d8}.pl-ok{opacity:.45}.pl-a{position:relative;height:auto;min-height:24px;margin-left:160px}.pl-arr{position:absolute;top:0;font-size:.74rem;white-space:nowrap;color:#7a5a12}
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
  return `${form}<div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h2>Le menu « À votre arrivée »</h2>${CZ.ini.gerer && !e ? `<button class="primary" id="snew">Ajouter un service</button>` : ""}</div>
    <div class="hint">Le voyageur commande depuis son lien, avant d'arriver. Vous fixez le prix, vous notez votre coût réel : la différence est votre marge. Le règlement passe par vous (sur place ou avec la réservation), jamais par ${esc(typeof PRODUIT !== "undefined" && PRODUIT.nom ? PRODUIT.nom : "le logiciel")}.</div>
    ${S.services.length ? `<div class="scroll"><table class="tbl"><thead><tr><th>Service</th><th>Prix</th><th>Coût</th><th>Marge</th><th>Délai</th><th></th></tr></thead><tbody>${S.services.map(s => `<tr style="${+s.actif ? "" : "opacity:.5"}"><td><b>${esc(s.nom)}</b><div class="hint">${esc(s.description || "")}</div></td><td>${czDH(s.prix)}</td><td>${czDH(s.cout)}</td><td><b>${czDH(s.prix - s.cout)}</b>${+s.prix ? `<div class="hint">${Math.round((s.prix - s.cout) / s.prix * 100)} %</div>` : ""}</td><td>${s.delai_h} h</td><td>${CZ.ini.gerer ? `<button class="btn ghost" data-se="${s.id}">Modifier</button>` : ""}</td></tr>`).join("")}</tbody></table></div>` : `<div class="muted">Aucun service. Commencez par l'eau, les fruits, quelques courses et le transfert depuis l'aéroport.</div>`}</div>
    <div class="card"><h2>Ce que rapportent les services</h2><div class="hint">Du ${esc(czD(S.from))} au ${esc(czD(S.to))}</div>
      <div style="display:flex;gap:28px;flex-wrap:wrap;margin:10px 0"><div><div class="hint">Encaissé</div><b style="font-size:1.4rem">${czDH(M.ca)}</b></div><div><div class="hint">Coût</div><b style="font-size:1.4rem">${czDH(M.cout)}</b></div><div><div class="hint">Marge</div><b style="font-size:1.4rem">${czDH(M.marge)}</b></div></div>
      ${M.lignes.length ? `<table class="tbl"><thead><tr><th>Service</th><th>Quantité</th><th>Encaissé</th><th>Marge</th></tr></thead><tbody>${M.lignes.map(l => `<tr><td>${esc(l.nom)}</td><td>${+l.n}</td><td>${czDH(l.ca)}</td><td>${czDH(l.marge)}</td></tr>`).join("")}</tbody></table>` : `<div class="muted">Aucune commande ce mois-ci.</div>`}</div>`;
}
function czBSrv(){
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
    <label>Au départ <span class="hint">· clés, poubelles, climatisation</span><input id="ldep" value="${esc(b.depart || "")}" placeholder="Remettre les clés dans la boîte, éteindre la climatisation"></label>
    <div class="hint" style="margin-top:6px">Étapes en photos, dans l'ordre du trajet (12 au plus) :</div><div id="let">${czEtapes(b.etapes)}</div><button type="button" class="btn ghost" id="letadd">Ajouter une étape</button>`;
}
const czEtapes = E => E.map((e, i) => `<div style="display:grid;grid-template-columns:84px 1fr 40px;gap:8px;align-items:center;margin-bottom:8px">
    <label style="width:84px;height:64px;border:1px dashed #bbb;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;font-size:.75rem;text-align:center">${e.url ? `<img src="${esc(e.url)}" alt="" style="width:100%;height:100%;object-fit:cover">` : "Photo"}<input type="file" accept="image/*" data-etp="${i}" hidden></label>
    <input data-ett="${i}" value="${esc(e.texte)}" placeholder="${esc(CZ_ETAPES_EX[i] || "Étape suivante")}"><button type="button" class="btn ghost" data-etx="${i}" title="Retirer">×</button></div>`).join("");
function czLireEtapes(){ return CZ.ed.livret.etapes.map((e, i) => Object.assign({}, e, {texte: ($(`[data-ett="${i}"]`) || {}).value ?? e.texte})); }
function czLireLivret(){ return {repere:$("#lrep").value, parking:$("#lpark").value, serrure:$("#lser").value, wifi_nom:$("#lwn").value, wifi_mdp:$("#lwm").value, urgence:$("#lurg").value, depart:$("#ldep").value, etapes:czLireEtapes().map(e => ({texte:e.texte, photo:e.photo}))}; }
function czBLivret(){
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
