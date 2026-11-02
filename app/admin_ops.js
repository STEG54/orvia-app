// Pilotage : opérations en cours (vue Plany), prise de poste du jour, registre unique du personnel.
const OP = { L:null, q:"", st:"actives", cli:"", zone:"" }; // zone : filtre de pôle (anim / hot)
const OP_ST = {creation:["En création", ""], a_terme:["À venir", "p-warn"], en_cours:["En cours", "p-ok"], terminee:["Terminée", "p-bad"], facturee:["Facturée", ""]};
async function loadOps(){ OP.L = (await gapi("operations_suivi")).operations; }
const opD = s => s ? s.slice(8, 10) + "/" + s.slice(5, 7) + "/" + s.slice(2, 4) : "";
const opFrac = (a, b) => `<span class="op-fr ${b && a >= b ? "ok" : a ? "mid" : "no"}">${a}/${b}</span>`;
function vOps(){
  const L = OP.L || [], q = OP.q.toLowerCase().trim();
  const F = L.filter(o => (OP.st === "tous" || (OP.st === "actives" ? ["creation", "a_terme", "en_cours", "terminee"].includes(o.statut) : o.statut === OP.st))
    && (!OP.cli || o.client === OP.cli) && (!OP.zone || o.pole === OP.zone) && (!q || (o.numero + " " + o.nom + " " + o.client).toLowerCase().includes(q)));
  const n = k => L.filter(o => o.statut === k).length, clis = [...new Set(L.map(o => o.client))].filter(Boolean).sort();
  const reste = F.reduce((a, o) => a + o.taches.length, 0);
  return `<div class="card accent"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>Opérations en cours</h2>
      <input id="op-q" placeholder="Rechercher un n°, un nom, un client" value="${esc(OP.q)}" style="max-width:320px"></div>
    <div class="op-st">${[["actives", "Toutes actives", L.filter(o => o.statut !== "facturee").length], ["creation", "En création", n("creation")], ["a_terme", "À venir", n("a_terme")], ["en_cours", "En cours", n("en_cours")], ["terminee", "Terminées", n("terminee")], ["facturee", "Facturées", n("facturee")], ["tous", "Tout", L.length]]
      .map(([k, l, c]) => `<button class="chip" data-opst="${k}" aria-pressed="${OP.st === k}">${l} <b>${c}</b></button>`).join("")}</div>
    <div class="kfilt"><select id="op-cli"><option value="">Tous les clients</option>${clis.map(c => `<option ${OP.cli === c ? "selected" : ""}>${esc(c)}</option>`).join("")}</select>
      <select id="op-zone"><option value="">Animation et hôtessariat</option><option value="anim" ${OP.zone === "anim" ? "selected" : ""}>Animation commerciale</option><option value="hot" ${OP.zone === "hot" ? "selected" : ""}>Accueil et hôtessariat</option></select>
      <span class="hint">${F.length} opération${F.length > 1 ? "s" : ""} · ${reste} chose${reste > 1 ? "s" : ""} à faire</span></div></div>
  <div class="card tablebox">${F.length ? `<div class="tablewrap"><table class="op-tab"><tr><th>N°</th><th>Opération</th><th>Client</th><th>Statut</th><th>Dates</th><th>Reste à faire</th><th class="num">Affectés</th><th class="num">Bookés</th><th></th></tr>
    ${F.map(o => `<tr><td><b>${esc(o.numero)}</b></td><td><button class="linkbtn" data-opdev="${o.id}">${esc(o.nom || "Sans objet")}</button><div class="hint">${o.pole === "hot" ? `<span class="tag-hot">Hôtessariat</span>` : `<span class="tag-anim">Animation</span>`} ${esc(o.type_op || "")}${o.ht ? " · " + gEur(o.ht) + " HT" : ""}</div></td><td>${esc(o.client)}</td>
      <td><span class="pill ${OP_ST[o.statut][1]}">${OP_ST[o.statut][0]}</span>${o.facture ? `<div class="hint">facture ${esc(o.facture)}</div>` : ""}</td>
      <td>${o.du ? opD(o.du) + (o.au !== o.du ? "<br>" + opD(o.au) : "") : `<span class="hint">aucune date</span>`}<div class="hint">${o.jours} j · ${o.creneaux} créneau${o.creneaux > 1 ? "x" : ""}</div></td>
      <td>${o.taches.length ? o.taches.map(t => `<button class="op-t" data-opgo="${t.go}" data-id="${o.id}">${esc(t.t)}</button>`).join("") : `<span class="hint">rien</span>`}</td>
      <td class="num">${opFrac(o.affectes, o.creneaux)}</td><td class="num">${opFrac(o.bookes, o.creneaux)}</td>
      <td class="kact"><button class="ghost sm" data-opgo="planning" data-id="${o.id}">Planning</button><button class="ghost sm" data-opgo="contrats" data-id="${o.id}">Contrats</button>${["en_cours", "terminee", "facturee"].includes(o.statut) ? `<button class="ghost sm" data-oprec="${o.id}">Bilan de présence</button>` : ""}</td></tr>${OP.rec && OP.rec.id === o.id ? `<tr><td colspan="9"><div class="pr-m"><div class="pr-h"><b>Bilan de présence à envoyer à la marque</b></div>
      <div class="row"><label>À<input id="or-a" value="${esc(OP.rec.a)}"></label><label>Objet<input id="or-s" value="${esc(OP.rec.sujet)}"></label></div><textarea id="or-t" rows="12">${esc(OP.rec.texte)}</textarea>
      <div class="btns"><button class="primary sm" id="or-go">Envoyer</button><button class="ghost sm" id="or-x">Fermer</button></div></div></td></tr>` : ""}`).join("")}</table></div>
    <div class="hint" style="margin-top:8px">Affectés : créneaux avec une personne. Bookés : créneaux dont la personne a signé son contrat. Cliquez une tâche pour aller à l'onglet concerné.</div>`
    : `<div class="empty">Aucune opération avec ces filtres.</div>`}</div>`;
}
function bOps(){
  const re = () => redraw();
  $("#op-q").oninput = e => { OP.q = e.target.value; const p = e.target.selectionStart; re(); const i = $("#op-q"); i.focus(); i.setSelectionRange(p, p); };
  document.querySelectorAll("[data-opst]").forEach(b => b.onclick = () => { OP.st = b.dataset.opst; re(); });
  $("#op-cli").onchange = e => { OP.cli = e.target.value; re(); }; $("#op-zone").onchange = e => { OP.zone = e.target.value; re(); };
  document.querySelectorAll("[data-opdev]").forEach(b => b.onclick = async () => { try { G.sub = "devis"; G.ed = (await gapi("doc", null, "id=" + b.dataset.opdev)).doc; go("gestion"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-oprec]").forEach(b => b.onclick = async () => { try { OP.rec = Object.assign(await gapi("presence_recap", null, "devis_id=" + b.dataset.oprec), {id:+b.dataset.oprec}); redraw(); } catch(e) { toast(e.message); } });
  if ($("#or-x")) $("#or-x").onclick = () => { OP.rec = null; redraw(); };
  if ($("#or-go")) $("#or-go").onclick = async () => { try { await gapi("presence_recap_envoyer", {devis_id:OP.rec.id, a:$("#or-a").value, sujet:$("#or-s").value, texte:$("#or-t").value}); toast("Bilan envoyé"); OP.rec = null; redraw(); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-opgo]").forEach(b => b.onclick = async () => { const t = b.dataset.opgo, id = +b.dataset.id;
    if (t === "rapports") return go("rapports");
    if (t === "evts") { A.crt = "evt"; EV.f.statut = "avalider"; EV.sel = null; return go("rapports"); }
    if (t === "devis") { try { G.sub = "devis"; G.ed = (await gapi("doc", null, "id=" + id)).doc; go("gestion"); } catch(e) { toast(e.message); } return; }
    if (t === "planning") { PL.id = id; PL.op = null; }
    if (t === "contrats" && typeof CT !== "undefined") { CT.sel = null; CT.C = null; CT.op = String(id); }
    G.sub = t; G.ed = null; go("gestion"); });
}

/* ---------- Prise de poste ---------- */
const PP = { date:null, L:null, filtre:"" };
async function loadPoste(){ PP.date = PP.date || gToday(); const [r, m] = await Promise.all([gapi("poste_jour", null, "date=" + PP.date + (PP.au ? "&au=" + PP.au : "")), gapi("presence_jour", null, "date=" + PP.date)]);
  PP.L = r.lignes; PP.etats = r.etats; PP.tol = r.tolerance; PP.rayon = r.rayon; PP.M = m.messages || []; }
/** E-mails aux marques : seulement quand il y a un problème (retard, absence, remplacement, relais). */
function vPresence(){
  const M = (PP.M || []).filter(m => m.incidents > 0 || m.envoi); if (!M.length) return "";
  return `<div class="card"><h2>Prévenir les marques</h2><div class="muted">Un e-mail par marque, seulement en cas de problème. Relisez, modifiez si besoin, puis envoyez (copie aux adresses des réglages).</div>
    ${M.map(m => `<div class="pr-m" data-prc="${m.client_id}"><div class="pr-h"><b>${esc(m.client)}</b> <span class="pill ${m.envoi && m.envoi.statut === "envoye" ? "p-ok" : m.envoi ? "" : "p-warn"}">${m.envoi ? (m.envoi.statut === "envoye" ? "envoyé " + esc((m.envoi.le || "").slice(11, 16)) + (m.envoi.par ? " par " + esc(m.envoi.par) : "") : "pas envoyé") : m.incidents + " problème" + (m.incidents > 1 ? "s" : "")}</span>${m.mode === "aucun" ? ` <span class="hint">· marque réglée sur « jamais »</span>` : ""}</div>
      ${m.envoi && m.envoi.statut === "envoye" ? `<pre class="pr-t">${esc(m.texte)}</pre>` : `<div class="row"><label>À<input data-pra value="${esc(m.a)}"></label><label>Objet<input data-prs value="${esc(m.sujet)}"></label></div>
      <textarea data-prt rows="9">${esc(m.texte)}</textarea><div class="btns"><button class="primary sm" data-prgo>Envoyer à la marque</button>${m.envoi ? "" : `<button class="ghost sm" data-prno>Ne pas envoyer</button>`}</div>`}</div>`).join("")}</div>`;
}
const PP_PILL = {a_venir:"", en_poste:"p-ok", present:"p-ok", retard:"p-warn", non_signale:"p-bad", absent:"p-bad"};
function vPoste(){
  const L = PP.L || [], n = k => L.filter(x => x.code === k).length, F = PP.filtre ? L.filter(x => x.code === PP.filtre || (PP.filtre === "en_poste" && x.code === "present")) : L;
  const txt = x => x.code === "retard" ? `Retard ${x.ecart} min` : x.code === "non_signale" ? (x.termine ? "Non signalé · journée terminée, à confirmer" : `Non signalé${x.ecart > 0 ? " · début il y a " + x.ecart + " min" : ""}`) : PP.etats[x.code];
  return `<div class="card accent"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>Prise de poste · ${PP.au && PP.au !== PP.date ? "du " + esc(dbJour(PP.date)) + " au " + esc(dbJour(PP.au)) : esc(dbJour(PP.date))}</h2>
      <div class="pp-nav"><button class="ghost sm" data-ppd="-1">‹</button><input type="date" id="pp-date" value="${PP.date}" style="width:auto"><span class="hint">au</span><input type="date" id="pp-au" value="${PP.au || ""}" min="${PP.date}" style="width:auto" title="Facultatif : pour voir une période"><button class="ghost sm" data-ppd="1">›</button><button class="ghost sm" id="pp-re">Actualiser</button></div></div>
    <div class="plan-test" style="margin:8px 0">Absent(e) : cliquez « Absent ». La journée reste sur son relevé d'heures, marquée absente, mais n'est ni payée (paye, marges) ni facturée à la marque (la facture créée depuis le devis la retire et l'indique dans les écarts). Remplacée en urgence (« Remplacer par… ») : la journée passe au (à la) remplaçant(e), qui est payé(e), et elle est facturée ; l'absence reste sur le relevé de la personne absente.</div>
    <div class="muted">Chaque intervenant(e) valide son arrivée dans l'appli (bouton « Je prends mon poste », ouvert 1 h 30 avant le début). L'heure et la position sont comparées au point d'intervention, dans le fuseau de la zone. Retard au-delà de ${PP.tol} min ; position à plus de ${PP.rayon} m signalée.</div>
    <div class="op-st">${[["", "Tous", L.length], ["en_poste", "En poste", n("en_poste") + n("present")], ["retard", "Retard", n("retard")], ["non_signale", "Non signalé", n("non_signale")], ["absent", "Absent", n("absent")], ["a_venir", "À venir", n("a_venir")]]
      .map(([k, l, c]) => `<button class="chip" data-ppf="${k}" aria-pressed="${PP.filtre === k}">${l} <b>${c}</b></button>`).join("")}</div></div>
  <div class="card tablebox">${F.length ? `<div class="tablewrap"><table><tr>${PP.au ? "<th>Date</th>" : ""}<th>Intervenant(e)</th><th>Point d'intervention</th><th>Horaires</th><th>État</th><th>Prise de poste</th><th></th></tr>
    ${F.map(x => `<tr>${PP.au ? `<td>${esc(dbJour(x.jour))}</td>` : ""}<td><b>${esc(x.nom)}</b>${x.telephone ? `<div class="hint"><a href="tel:${esc(x.telephone)}">${esc(x.telephone)}</a></div>` : ""}</td><td>${esc(x.magasin)}<div class="hint">${esc(x.client || "")} · ${esc(x.zone)}</div></td>
      <td>${esc(x.debut)} à ${esc(x.fin)}<div class="hint">heure locale ${esc(x.heure_locale)}</div></td>
      <td><span class="pill ${PP_PILL[x.code]}">${esc(txt(x))}</span>${x.cr ? `<div class="hint">compte rendu reçu</div>` : ""}${x.remplace_par ? `<div class="hint">remplacé(e) par <b>${esc(x.remplace_par)}</b></div>` : ""}${x.remplace ? `<div class="hint">remplace ${esc(x.remplace)} (absent(e))</div>` : ""}${x.partie ? `<div class="hint">journée partagée (${x.partie === 1 ? "1re partie" : "relais"})</div>` : ""}${x.par ? `<div class="hint">corrigé par ${esc(x.par)}${x.note ? " : " + esc(x.note) : ""}</div>` : ""}</td>
      <td>${x.heure ? `<b>${esc(x.heure)}</b>${x.distance != null ? `<div class="hint ${x.hors_zone ? "pp-loin" : ""}">${x.distance < 1000 ? x.distance + " m" : (x.distance / 1000).toFixed(1).replace(".", ",") + " km"} du point d'intervention${x.hors_zone ? " · hors du point d'intervention" : ""}</div>` : `<div class="hint">sans position</div>`}` : `<span class="hint">—</span>`}</td>
      <td class="kact">${!x.remplace_par ? `<button class="ghost sm" data-ppart="${x.id}">Partager la journée</button>` : ""}${x.code !== "absent" ? `<button class="ghost sm" data-ppm="${x.id}" data-aid="${x.animatrice_id}" data-s="absent">Absent</button>` : ""}${!["en_poste", "present", "retard", "a_venir"].includes(x.code) && !x.remplace_par ? `<button class="ghost sm" data-ppm="${x.id}" data-aid="${x.animatrice_id}" data-s="present">Présent</button>` : ""}${x.par && !x.remplace_par ? `<button class="ghost sm" data-ppm="${x.id}" data-aid="${x.animatrice_id}" data-s="">Annuler</button>` : ""}
        ${x.code === "absent" && !x.remplace_par ? `<select class="pp-rep" data-pprep="${x.id}"><option value="">Remplacer par…</option>${ppCandidats(x)}</select>` : ""}</td></tr>`).join("")}</table></div>`
    : `<div class="empty">Aucune mission ${PP.au ? "sur cette période" : "ce jour-là"}.</div>`}</div>${vPresence()}${ppPartageForm()}`;
}
/** Partager une journée : relais par une autre personne (malaise, urgence) et/ou demi-journée dans un autre point d'intervention. */
function ppPartageForm(){
  const x = (PP.L || []).find(y => y.id === PP.partage && !y.remplace_par); if (!x) return "";
  const mid = (+x.debut.slice(0, 2) + +x.fin.slice(0, 2)) / 2, h = String(Math.floor(mid)).padStart(2, "0") + ":00";
  const pers = A.L.animatrices.filter(a => +a.actif && !["standby", "refuse", "attente"].includes(a.statut)).sort((a, b) => (a.nom + a.prenom).localeCompare(b.nom + b.prenom));
  return `<div class="card accent" id="pp-form"><h2>Partager la journée de ${esc(x.nom)} · ${esc(x.magasin)} · ${esc(x.debut)} à ${esc(x.fin)}</h2>
    <div class="muted">La 1re partie s'arrête à l'heure du relais. La 2e partie va à une autre personne (relais en cours de journée) et/ou dans un autre point d'intervention (demi-journée). Chacun(e) est payé(e) pour ses heures, ses km et son repas (au-delà de 6 h) ; les contrats concernés passent « à renvoyer » ou « avenant ». La marque est facturée une seule journée.</div>
    <div class="row3"><label>Heure du relais<input type="time" id="pf-h" value="${h}" min="${x.debut}" max="${x.fin}"></label>
      <label>2e partie : personne<select id="pf-a"><option value="${x.animatrice_id}">${esc(x.nom)} (la même)</option>${pers.filter(a => +a.id !== +x.animatrice_id).map(a => `<option value="${a.id}" ${a.zone !== x.zone ? "hidden" : ""}>${esc(nm(a))}</option>`).join("")}</select></label>
      <label>2e partie : point d'intervention<select id="pf-m"><option value="">${esc(x.magasin)} (le même)</option>${A.L.magasins.filter(m => m.zone === x.zone && +m.actif && m.nom !== x.magasin).map(m => `<option>${esc(m.nom)}</option>`).join("")}</select></label></div>
    <div class="row3"><label>Pause 1re partie (min)<input id="pf-p1" type="number" min="0" step="15" value="0"></label><label>Pause 2e partie (min)<input id="pf-p2" type="number" min="0" step="15" value="0"></label><span></span></div>
    <div class="btns"><button class="primary" id="pf-go">Partager</button><button class="ghost" id="pf-x">Fermer</button></div></div>`;
}
/** Remplaçant(e)s possibles : actifs de la zone, sauf les personnes déjà prises ce jour-là. */
function ppCandidats(x){ const pris = new Set((PP.L || []).filter(y => !y.remplace_par).map(y => +y.animatrice_id));
  return A.L.animatrices.filter(a => +a.actif && !["standby", "refuse", "attente"].includes(a.statut) && a.zone === x.zone && !pris.has(+a.id)).sort((a, b) => (a.nom + a.prenom).localeCompare(b.nom + b.prenom))
    .map(a => `<option value="${a.id}">${esc(nm(a))}</option>`).join(""); }
function bPoste(){
  const re = async () => { try { await loadPoste(); } catch(e) { toast(e.message); } redraw(); };
  $("#pp-date").onchange = e => { PP.date = e.target.value || gToday(); if (PP.au && PP.au <= PP.date) PP.au = ""; re(); };
  $("#pp-au").onchange = e => { PP.au = e.target.value > PP.date ? e.target.value : ""; re(); };
  document.querySelectorAll("[data-ppd]").forEach(b => b.onclick = () => { PP.date = gAdd(PP.date, +b.dataset.ppd); if (PP.au) PP.au = gAdd(PP.au, +b.dataset.ppd); re(); });
  $("#pp-re").onclick = re;
  document.querySelectorAll("[data-prc]").forEach(box => { const cid = +box.dataset.prc, go = box.querySelector("[data-prgo]"), no = box.querySelector("[data-prno]");
    if (go) go.onclick = async () => { go.disabled = true; try { await gapi("presence_envoyer", {client_id:cid, date:PP.date, a:box.querySelector("[data-pra]").value, sujet:box.querySelector("[data-prs]").value, texte:box.querySelector("[data-prt]").value}); toast("E-mail envoyé à la marque"); re(); } catch(e) { toast(e.message); go.disabled = false; } };
    if (no) no.onclick = async () => { try { await gapi("presence_ignorer", {client_id:cid, date:PP.date}); re(); } catch(e) { toast(e.message); } }; });
  document.querySelectorAll("[data-ppart]").forEach(b => b.onclick = () => { PP.partage = +b.dataset.ppart; redraw(); $("#pp-form").scrollIntoView({behavior:"smooth"}); });
  if ($("#pf-x")) $("#pf-x").onclick = () => { PP.partage = 0; redraw(); };
  if ($("#pf-go")) $("#pf-go").onclick = async () => { try { await gapi("partager", {mission_id:PP.partage, heure:$("#pf-h").value, animatrice_id:+$("#pf-a").value, magasin:$("#pf-m").value, pause1:+$("#pf-p1").value, pause2:+$("#pf-p2").value});
    PP.partage = 0; toast("Journée partagée. Pensez aux contrats (avenant) et, pour un(e) nouvel(le) venu(e), à la DPAE."); re(); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-pprep]").forEach(sl => sl.onchange = async () => { if (!sl.value) return;
    try { const r = await gapi("poste_remplacer", {mission_id:+sl.dataset.pprep, animatrice_id:+sl.value}); toast(r.absent + " remplacé(e) par " + r.nom + ". Pensez à la prévenir, à son contrat et à sa DPAE."); re(); } catch(e) { toast(e.message); sl.value = ""; } });
  document.querySelectorAll("[data-ppf]").forEach(b => b.onclick = () => { PP.filtre = b.dataset.ppf; redraw(); });
  document.querySelectorAll("[data-ppm]").forEach(b => b.onclick = async () => { const s = b.dataset.s; let note = "";
    if (s === "absent" && b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer : non payé, non facturé"; return; }
    if (s === "absent") note = prompt("Motif de l'absence (facultatif)", "") || "";
    if (s === "present") note = prompt("Présent(e) : comment l'avez-vous su ? (appel, pas de réseau…)", "Prévenu(e) par téléphone") || "";
    try { await gapi("poste_marquer", {mission_id:+b.dataset.ppm, animatrice_id:+b.dataset.aid, statut:s, note}); toast(s ? "Marqué " + (s === "absent" ? "absent(e)" : "présent(e)") : "Correction annulée"); re(); } catch(e) { toast(e.message); } });
}

/* ---------- Registre unique du personnel ---------- */
const RUP = { L:null, q:"" };
async function loadRup(){ RUP.L = (await gapi("rup")).lignes; }
function vRup(){
  const L = RUP.L || [], q = RUP.q.toLowerCase().trim(), F = q ? L.filter(x => (x.nom + " " + x.prenom + " " + x.matricule + " " + x.numero).toLowerCase().includes(q)) : L;
  const manque = L.filter(x => !x.nationalite || !x.naissance || !x.sexe || (x.etranger && !x.titre_travail)).length;
  return `<div class="card accent"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>Registre unique du personnel</h2>
      <div class="pp-nav"><a class="btn primary" href="gestion_api.php?action=rup_pdf" target="_blank" rel="noopener">PDF pour un contrôle</a><a class="btn ghost" href="gestion_api.php?action=rup_xlsx">Excel</a></div></div>
    <div class="muted">Tenu automatiquement à partir des contrats de travail : une ligne par embauche (chaque CDD), dans l'ordre des entrées, avec les mentions obligatoires (art. L1221-13 et D1221-23 du Code du travail). À présenter à l'inspection du travail ou à l'Urssaf sur demande. Les informations viennent du contrat et de la fiche de la personne.</div>
    ${manque ? `<div class="plan-test">${manque} ligne${manque > 1 ? "s" : ""} incomplète${manque > 1 ? "s" : ""} (nationalité, date de naissance, civilité ou titre de travail) : complétez la fiche de la personne.</div>` : ""}
    <input id="rup-q" placeholder="Rechercher un nom, un matricule, un n° de contrat" value="${esc(RUP.q)}" style="max-width:340px;margin-top:8px"></div>
  <div class="card tablebox">${F.length ? `<div class="tablewrap"><table><tr><th>N°</th><th>Salarié(e)</th><th>Sexe</th><th>Nationalité</th><th>Naissance</th><th>Emploi et qualification</th><th>Contrat</th><th>Entrée</th><th>Sortie</th></tr>
    ${F.map(x => `<tr><td>${x.ordre}</td><td><b>${esc(x.nom)} ${esc(x.prenom)}</b>${x.nom_naissance ? `<div class="hint">née ${esc(x.nom_naissance)}</div>` : ""}<div class="hint">${esc(x.matricule)}</div></td><td>${esc(x.sexe) || `<span class="pp-loin">?</span>`}</td>
      <td>${esc(x.nationalite) || `<span class="pp-loin">à compléter</span>`}${x.etranger && !x.titre_travail ? `<div class="hint pp-loin">titre de travail ?</div>` : ""}</td><td>${x.naissance ? opD(x.naissance) : `<span class="pp-loin">?</span>`}</td>
      <td>${esc(x.emploi)}<div class="hint">${esc(x.qualification)}</div></td><td>n°${esc(x.numero)}<div class="hint">${esc(x.statut)}</div></td><td>${opD(x.entree)}</td><td>${opD(x.sortie)}</td></tr>`).join("")}</table></div>`
    : `<div class="empty">Aucun contrat pour l'instant.</div>`}</div>`;
}
function bRup(){ $("#rup-q").oninput = e => { RUP.q = e.target.value; const p = e.target.selectionStart; redraw(); const i = $("#rup-q"); i.focus(); i.setSelectionRange(p, p); }; }

/* ---------- Notes de frais ---------- */
const NF = { L:null, statut:"a_valider", mois:"", qui:0, sel:new Set() };
async function loadNdf(){ const q = new URLSearchParams({statut:NF.statut, mois:NF.mois, animatrice_id:NF.qui || ""}).toString(); const r = await gapi("ndf_admin", null, q); NF.L = r.ndf; NF.types = r.types; NF.statuts = r.statuts; NF.nb = r.a_valider; NF.sel = new Set(); const f = await gapi("factures_ae").catch(() => ({factures:[], statuts:{}})); NF.fa = f.factures; NF.fast = f.statuts; }
function vNdfAdm(){
  const L = NF.L || [], tot = L.reduce((a, x) => a + x.montant, 0), pill = {a_valider:"p-warn", validee:"p-ok", refusee:"p-bad", payee:""};
  const par = {}; L.forEach(x => { par[x.nom] = (par[x.nom] || 0) + x.montant; });
  return `<div class="card accent"><h2>Notes de frais${NF.nb ? ` · ${NF.nb} à valider` : ""}</h2>
    <div class="muted">Envoyées depuis l'appli avec la photo du ticket. Une note validée est remboursée avec la paye du mois de la dépense (colonne « Frais divers » du fichier de paye) et comptée dans les marges de l'opération. La personne reçoit une notification à chaque décision.</div>
    <div class="kfilt"><select id="nf-st"><option value="">Tous statuts</option>${Object.entries(NF.statuts || {}).map(([k, l]) => `<option value="${k}" ${NF.statut === k ? "selected" : ""}>${l}</option>`).join("")}</select>
      <input type="month" id="nf-mo" value="${NF.mois}" style="width:auto"><select id="nf-qui"><option value="">Toutes les personnes</option>${[...A.L.animatrices].filter(a => +a.actif).sort((a, b) => (a.nom + a.prenom).localeCompare(b.nom + b.prenom)).map(a => `<option value="${a.id}" ${+NF.qui === +a.id ? "selected" : ""}>${esc(nm(a))}</option>`).join("")}</select>
      <span class="hint">${L.length} note${L.length > 1 ? "s" : ""} · ${gEur(tot)}</span></div></div>
  <div class="card tablebox">${L.length ? `<div class="pp-nav" style="margin-bottom:8px"><button class="primary sm" data-nfd="validee">Valider la sélection</button><button class="ghost sm" data-nfd="refusee">Refuser</button><button class="ghost sm" data-nfd="payee">Marquer payées</button><span class="hint" id="nf-n">0 sélectionnée</span></div>
    <div class="tablewrap"><table><tr><th><input type="checkbox" id="nf-all" style="width:auto"></th><th>Date</th><th>Personne</th><th>Dépense</th><th>Mission</th><th class="num">Montant</th><th>Ticket</th><th>État</th></tr>
    ${L.map(x => `<tr><td><input type="checkbox" data-nfs="${x.id}" style="width:auto"></td><td>${esc(fmtD(x.jour))}</td><td><b>${esc(x.nom)}</b></td><td>${esc(x.type_txt)}${x.commentaire ? `<div class="hint">${esc(x.commentaire)}</div>` : ""}</td>
      <td>${esc(x.magasin || "")}${x.objet ? `<div class="hint">${esc(x.objet)}</div>` : ""}</td><td class="num"><b>${gEur(x.montant)}</b></td>
      <td>${x.ticket ? `<a class="btn ghost sm" href="api.php?action=ndf_ticket&id=${x.id}" target="_blank" rel="noopener">Voir</a>` : ""}</td>
      <td><span class="pill ${pill[x.statut]}">${esc(NF.statuts[x.statut])}</span>${x.motif ? `<div class="hint">${esc(x.motif)}</div>` : ""}${x.par ? `<div class="hint">${esc(x.par)}</div>` : ""}</td></tr>`).join("")}</table></div>
    <div class="hint" style="margin-top:8px">Par personne : ${Object.entries(par).map(([n, v]) => esc(n) + " " + gEur(v)).join(" · ")}</div>`
    : `<div class="empty">Aucune note avec ces filtres.</div>`}</div>${vFactAE()}`;
}
function vFactAE(){
  const L = NF.fa || []; if (!L.length) return `<div class="card"><h2>Factures des auto-entrepreneurs</h2><div class="muted">Les intervenants au statut auto-entrepreneur (SIRET dans leur fiche) déposent leurs factures depuis l'appli. Elles apparaîtront ici.</div></div>`;
  const pill = {recue:"p-warn", validee:"p-ok", payee:"", refusee:"p-bad"};
  return `<div class="card tablebox"><h2>Factures des auto-entrepreneurs · ${L.filter(f => f.statut === "recue").length} à traiter</h2><div class="tablewrap"><table><tr><th>Reçue le</th><th>Intervenant(e)</th><th>N°</th><th>Mois</th><th>Opération</th><th class="num">Montant</th><th>Statut</th><th></th></tr>
    ${L.map(f => `<tr><td>${esc(fmtD(f.created_at.slice(0, 10)))}</td><td><b>${esc(f.qui)}</b><div class="hint">${esc(f.matricule || "")}</div></td><td>${esc(f.numero)}</td><td>${esc(f.periode)}</td><td>${esc(f.objet || "")}</td><td class="num">${gEur(f.montant)}</td>
      <td><span class="pill ${pill[f.statut] || ""}">${esc(f.statut_txt)}</span></td><td style="white-space:nowrap"><a class="btn ghost sm" href="${f.url}" target="_blank" rel="noopener">Voir</a>
      <select data-fast="${f.id}" style="width:auto">${Object.entries(NF.fast || {}).map(([k, l]) => `<option value="${k}" ${f.statut === k ? "selected" : ""}>${l}</option>`).join("")}</select></td></tr>`).join("")}</table></div></div>`;
}
function bNdfAdm(){
  document.querySelectorAll("[data-fast]").forEach(s => s.onchange = async () => { try { await gapi("facture_ae_statut", {ids:[+s.dataset.fast], statut:s.value}); toast("Statut enregistré, la personne est prévenue"); go("gestion"); } catch(e) { toast(e.message); } });
  const re = async () => { try { await loadNdf(); } catch(e) { toast(e.message); } redraw(); };
  $("#nf-st").onchange = e => { NF.statut = e.target.value; re(); }; $("#nf-mo").onchange = e => { NF.mois = e.target.value; re(); }; $("#nf-qui").onchange = e => { NF.qui = +e.target.value || 0; re(); };
  const maj = () => { if ($("#nf-n")) $("#nf-n").textContent = NF.sel.size + " sélectionnée" + (NF.sel.size > 1 ? "s" : ""); };
  document.querySelectorAll("[data-nfs]").forEach(c => c.onchange = () => { c.checked ? NF.sel.add(+c.dataset.nfs) : NF.sel.delete(+c.dataset.nfs); maj(); });
  if ($("#nf-all")) $("#nf-all").onchange = e => document.querySelectorAll("[data-nfs]").forEach(c => { c.checked = e.target.checked; c.onchange(); });
  document.querySelectorAll("[data-nfd]").forEach(b => b.onclick = async () => { if (!NF.sel.size) return toast("Cochez au moins une note."); let motif = "";
    if (b.dataset.nfd === "refusee") { motif = prompt("Motif du refus (la personne le verra)", "Ticket illisible") || ""; if (!motif) return; }
    try { await gapi("ndf_decider", {ids:[...NF.sel], statut:b.dataset.nfd, motif}); toast("Notes mises à jour"); re(); } catch(e) { toast(e.message); } });
}
