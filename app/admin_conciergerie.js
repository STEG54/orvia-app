// ORVIA : administration de la conciergerie (Aujourd'hui, Logements, Réservations, Missions, Signalements, Propriétaires).
const CZ = { ini:null, jour:null, logs:null, ed:null, res:null, resF:{ from:"", to:"", logement:"" }, det:null, mis:null, misF:{ from:"", to:"" }, mdet:null, inc:null, incS:"ouvert", prop:null, lien:null, decl:null };
const CZ_SUBS = [["cz_jour","Aujourd'hui"],["cz_reservations","Réservations"],["cz_missions","Missions"],["cz_logements","Logements"],["cz_incidents","Signalements"],["cz_proprietaires","Propriétaires"]];
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
async function czCopier(txt, msg){ try { await navigator.clipboard.writeText(txt); toast(msg || "Copié"); } catch(e) { prompt("Copiez :", txt); } }

async function czLoad(){
  if (CZ.vu !== G.sub) { CZ.vu = G.sub; CZ.det = null; CZ.mdet = null; CZ.ed = null; CZ.lien = null; CZ.decl = null; }
  if (!CZ.ini) CZ.ini = await czapi("init");
  if (!CZ.resF.from) { const t = czIso(new Date()); CZ.resF.from = czAdd(t, -3); CZ.resF.to = czAdd(t, 30); CZ.misF.from = t; CZ.misF.to = czAdd(t, 7); }
  if (G.sub === "cz_jour") CZ.jour = await czapi("aujourdhui");
  if (G.sub === "cz_reservations") CZ.res = (await czapi("reservations", null, `from=${CZ.resF.from}&to=${CZ.resF.to}&logement=${CZ.resF.logement}`)).reservations;
  if (G.sub === "cz_missions") CZ.mis = (await czapi("missions", null, `from=${CZ.misF.from}&to=${CZ.misF.to}`)).missions;
  if (G.sub === "cz_incidents") CZ.inc = (await czapi("incidents", null, "statut=" + CZ.incS)).incidents;
  if (G.sub === "cz_logements" || G.sub === "cz_proprietaires") CZ.ini = await czapi("init");
}
function czView(){
  const v = {cz_jour:czVJour, cz_logements:czVLogements, cz_reservations:czVRes, cz_missions:czVMis, cz_incidents:czVInc, cz_proprietaires:czVProp}[G.sub];
  return v ? v() : "";
}
function czBind(){
  const b = {cz_jour:czBJour, cz_logements:czBLogements, cz_reservations:czBRes, cz_missions:czBMis, cz_incidents:czBInc, cz_proprietaires:czBProp}[G.sub];
  if (b) b();
}
const czRe = () => go("gestion");

/* ---------- Aujourd'hui ---------- */
function czVJour(){
  const J = CZ.jour, auj = czIso(new Date());
  const arr = J.arrivees.map(r => `<div class="card" style="display:grid;gap:8px">
      <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><b>${esc(r.logement)}</b>${czFv(r.fv)}</div>
      <div class="muted">${r.arrivee === auj ? "Arrivée aujourd'hui" : "Arrivée demain"} · départ ${esc(czD(r.depart))} · ${esc((CZ.ini.sources || {})[r.source] || r.source)}</div>
      <div class="hint">Fiches ${r.fv.nb_fiches}/${r.fv.attendues} · ménage ${r.fv.menage ? "validé" : "à faire"} · ${r.fv.incidents ? r.fv.incidents + " incident(s) bloquant(s)" : "aucun incident bloquant"}${r.code_envoye ? " · code envoyé" : ""}</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn" data-lien="${esc(r.lien)}">Copier le lien voyageur</button>${!r.code_envoye && r.fv.ok ? `<button class="primary" data-code="${r.id}">Envoyer le code</button>` : ""}<button class="btn ghost" data-det="${r.id}">Détail</button></div></div>`).join("");
  const mis = J.missions.map(m => `<tr data-mis="${m.id}" style="cursor:pointer"><td>${m.date < auj ? `<span class="pill p-bad">En retard</span> ` : ""}${esc(m.heure || "")}</td><td>${esc(m.logement)}</td><td>${esc((CZ.ini.types || {})[m.type] || m.type)}</td><td>${esc(m.agent || "Non attribuée")}</td><td>${czSt(m.statut)}</td></tr>`).join("");
  const inc = J.incidents.map(i => `<li>${i.bloquant == 1 ? `<span class="pill p-bad">Bloquant</span> ` : ""}<b>${esc(i.logement)}</b> · ${esc(i.description)}</li>`).join("");
  return `<div class="card"><h2>Arrivées</h2>${arr || `<div class="muted">Aucune arrivée aujourd'hui ni demain.</div>`}</div>
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
  if ($("#dann")) $("#dann").onclick = async () => { if (!confirm("Annuler cette réservation ? La mission de ménage prévue sera retirée.")) return; try { await czapi("reservation_annuler", {id:r.id}); CZ.det = null; czRe(); } catch(e) { toast(e.message); } };
}

/* ---------- Missions ---------- */
function czVMis(){
  if (CZ.mdet) return czVMisDet();
  const A = CZ.ini.agents, par = {}; (CZ.mis || []).forEach(m => (par[m.date] = par[m.date] || []).push(m));
  const jours = Object.keys(par).sort().map(d => `<div class="card"><h3>${esc(czD(d))}</h3><div class="scroll"><table class="tbl"><thead><tr><th>Heure</th><th>Logement</th><th>Type</th><th>Équipe</th><th>État</th><th></th></tr></thead><tbody>
    ${par[d].map(m => `<tr><td>${esc(m.heure)}</td><td>${esc(m.logement)}</td><td>${esc((CZ.ini.types || {})[m.type] || m.type)}</td>
      <td>${CZ.ini.gerer && m.statut !== "faite" ? `<select data-agent="${m.id}" data-date="${m.date}" data-heure="${esc(m.heure)}"><option value="">Non attribuée</option>${A.map(a => `<option value="${a.id}" ${+m.animatrice_id === +a.id ? "selected" : ""}>${esc(a.prenom + " " + (a.nom || ""))}</option>`).join("")}</select>` : esc(m.agent || "")}</td>
      <td>${czSt(m.statut)}</td><td><button class="btn ghost" data-mis="${m.id}">Voir</button></td></tr>`).join("")}</tbody></table></div></div>`).join("");
  return `<div class="card"><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end"><label>Du<input type="date" id="mf" value="${CZ.misF.from}"></label><label>Au<input type="date" id="mt" value="${CZ.misF.to}"></label><button class="btn" id="mgo">Afficher</button>
    ${CZ.ini.gerer ? `<button class="primary" id="mnew">Mission de contrôle ou de maintenance</button>` : ""}</div>
    ${A.length ? "" : `<div class="hint">Aucun membre d'équipe : ajoutez vos agents dans « Équipe » (ils se connectent à l'appli avec leur code).</div>`}</div>
    ${jours || `<div class="card muted">Aucune mission sur la période.</div>`}`;
}
function czBMis(){
  if (CZ.mdet) return czBMisDet();
  $("#mgo").onclick = () => { CZ.misF = {from:$("#mf").value, to:$("#mt").value}; czRe(); };
  document.querySelectorAll("[data-mis]").forEach(b => b.onclick = () => czMission(+b.dataset.mis));
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
    ${m.manques.length && m.statut !== "faite" ? `<div class="hint">Reste à faire : ${esc(m.manques.join(" · "))}</div>` : ""}
    ${CZ.ini.gerer && m.statut === "faite" ? `<button class="btn ghost" id="mrep">Demander de reprendre</button>` : ""}</div>
    ${m.pieces.map(p => `<div class="card"><div style="display:flex;justify-content:space-between"><b>${esc(p.piece)}</b>${p.ok ? `<span class="pill p-ok">Validée</span>` : `<span class="pill">À faire</span>`}</div>${p.consignes ? `<div class="hint">${esc(p.consignes)}</div>` : ""}
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">${p.urls.map((u, i) => `<a href="${esc(p.grandes[i])}" target="_blank" rel="noopener"><img src="${esc(u)}" alt="Photo ${esc(p.piece)}" style="width:110px;height:110px;object-fit:cover"></a>`).join("") || `<span class="hint">Aucune photo (${p.photos} demandée(s))</span>`}</div></div>`).join("")}`;
}
function czBMisDet(){
  $("#mback").onclick = () => { CZ.mdet = null; czRe(); };
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
      <label>Heure habituelle du ménage<input type="time" id="rch" value="${esc(R.cz_heure_menage)}"></label><button class="btn" id="rsave">Enregistrer les réglages</button></div>` : ""}`;
}
function czBLogements(){
  if (CZ.ed) return czBLogEd();
  if ($("#lnew")) $("#lnew").onclick = () => { CZ.ed = {id:0, nom:"", ville:"", adresse:"", proprietaire_id:null, agent_id:null, acces:"", code:"", kit_linge:"", duree_menage:120, ical:[], notes:"", actif:1, checklist:[{piece:"Chambre",photos:2},{piece:"Salle de bain",photos:2},{piece:"Cuisine",photos:2},{piece:"Salon",photos:2}]}; czRe(); };
  document.querySelectorAll("[data-led]").forEach(b => b.onclick = async () => { try { CZ.ed = (await czapi("logement_get", null, "id=" + b.dataset.led)).logement; czRe(); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-sync]").forEach(b => b.onclick = async () => { b.disabled = true; try { const r = await czapi("logement_sync", {id:+b.dataset.sync}); toast(`${r.ajoutees} nouvelle(s), ${r.modifiees} modifiée(s), ${r.annulees} annulée(s).`); CZ.ini = null; czRe(); } catch(e) { toast(e.message); b.disabled = false; } });
  if ($("#rsave")) $("#rsave").onclick = async () => { try { await czapi("reglages_save", {cz_conservation_jours:+$("#rcj").value, cz_copie_piece:$("#rcp").checked, cz_code_auto:$("#rca").checked, cz_heure_menage:$("#rch").value}); CZ.ini = null; toast("Réglages enregistrés."); } catch(e) { toast(e.message); } };
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
  czBDel();
  $("#lsave").onclick = async () => {
    const l = CZ.ed, b = $("#lsave"); b.disabled = true;
    try { const r = await czapi("logement_save", {id:l.id, nom:$("#ln").value, ville:$("#lv").value, adresse:$("#la").value, proprietaire_id:+$("#lp").value, agent_id:+$("#lag").value, kit_linge:$("#lk").value, duree_menage:+$("#ld").value,
        acces:$("#lacc").value, code:$("#lc").value, ical:$("#li").value, checklist:czLireChk(), notes:$("#lno").value, actif: $("#lact") ? $("#lact").checked : true});
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
