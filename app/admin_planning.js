// Planning par opération (un devis) : chaque créneau du devis reçoit une personne. Remplacements, annulations et reports avec accord, tout est tracé.
const PL = { ops:null, ouvert:false, id:0, op:null, sel:new Set(), notif:true, form:null, of:null, mode:"creneau", pers:0, add:new Set(), del:new Set() };
async function papi(action, body, query){
  const r = await fetch("planning_api.php?action=" + action + (query ? "&" + query : ""), body ? {method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/json","X-Requested-With":"amaya"}, body:JSON.stringify(body)} : {credentials:"same-origin"});
  if (typeof sessionExpiree === "function") sessionExpiree(r);
  const t = await r.text(); let d = {}; try { d = JSON.parse(t); } catch(e) {}
  if (!r.ok) { const e = new Error(d.error || "Erreur " + r.status); e.status = r.status; throw e; } return d;
}
const pNom = a => a ? (a.nom.toUpperCase() + " " + a.prenom.charAt(0).toUpperCase() + a.prenom.slice(1).toLowerCase()) : "";
const pDay = iso => new Date(iso + "T12:00:00").toLocaleDateString("fr-FR", {weekday:"short", day:"numeric", month:"short"});
const pStat = s => s === "accepte" ? `<span class="pill p-ok">accepté</span>` : s === "envoye" ? `<span class="pill p-warn">envoyé</span>` : `<span class="pill">brouillon</span>`;
async function loadPlanning(){
  const r = await papi("operations"); PL.ops = r.operations; PL.ouvert = r.ouvert;
  if (!PL.ops.find(o => o.id === PL.id)) PL.id = PL.ops.length ? PL.ops[0].id : 0;
  PL.op = PL.id ? await papi("operation", null, "id=" + PL.id) : null;
}
// Personnes déjà prises ce jour-là ailleurs : grisées, impossible de les choisir.
function pOpts(cur, zone, s){
  const L = A.L.animatrices.filter(a => +a.actif && !["standby", "refuse", "attente"].includes(a.statut) || (cur && +a.id === +cur)), zs = [...new Set([zone, ...A.L.zones])].filter(Boolean), occ = s && PL.op && PL.op.occupees ? (PL.op.occupees[s.date] || {}) : {};
  const pris = a => (occ[a.id] || []).filter(x => x.key !== s.key && !(s.debut && (x.fin <= s.debut || x.debut >= s.fin)));
  return `<option value="">À pourvoir</option>` + zs.map(z => `<optgroup label="${esc(z)}">${L.filter(a => a.zone === z).sort((x, y) => (x.nom + x.prenom).localeCompare(y.nom + y.prenom)).map(a => { const p = s ? pris(a) : [];
    const dp = s && PL.op && PL.op.dispos ? ((PL.op.dispos[s.date] || {})[a.id] || "") : "";
    return `<option value="${a.id}" ${+cur === +a.id ? "selected" : ""} ${p.length ? "disabled" : ""}>${esc(pNom(a))}${p.length ? " · déjà à " + esc(p[0].magasin || "un autre endroit") : dp === "o" ? " · dispo ✓" : dp === "n" ? " · indisponible" : ""}</option>`; }).join("")}</optgroup>`).join("");
}
// Offres de mission : proposer des dates à pourvoir aux intervenants autour du point d'intervention, puis choisir parmi les personnes positionnées.
function vOffres(){
  const C = PL.op.creneaux, libres = C.filter(s => s.statut !== "annulee" && !s.animatrice_id), F = PL.of, O = PL.op.offres || [];
  let form = "";
  if (F) {
    const A = F.apercu;
    form = `<div class="plan-form" style="padding:12px;border-radius:10px;background:var(--sand);display:flex;flex-direction:column;gap:10px">
      <b>Proposer ${F.keys.length} date${F.keys.length > 1 ? "s" : ""}</b><div class="hint">${F.keys.map(k => { const s = C.find(x => x.key === k); return s ? esc(pDay(s.date)) + " " + esc(s.magasin) : ""; }).join(" · ")}</div>
      <div class="row3"><label>Envoyer à<select id="of-r">${[[5, "moins de 5 km"], [10, "moins de 10 km"], [20, "moins de 20 km"], [30, "moins de 30 km"], [50, "moins de 50 km"], [0, "toute la zone (sans limite de km)"]].map(([v, l]) => `<option value="${v}" ${+F.rayon === v ? "selected" : ""}>${l}</option>`).join("")}</select></label>
        <label class="ck" style="align-self:end"><input type="checkbox" id="of-s" ${F.sans ? "checked" : ""}><span>Inclure les personnes sans ville connue</span></label><span></span></div>
      <label>Message en plus <span class="hint">facultatif</span><textarea id="of-m" rows="2" placeholder="Ex. Formation produit prévue la veille, tenue noire.">${esc(F.msg || "")}</textarea></label>
      ${A ? `<div><b>${A.destinataires.length - F.exclus.size} destinataire${A.destinataires.length - F.exclus.size > 1 ? "s" : ""}</b> <span class="hint">· décochez celles et ceux à exclure${A.test ? " · mode test : les e-mails vous arrivent à vous" : ""}</span>
        <div class="row3" style="margin-top:6px"><input id="of-q" placeholder="Rechercher un nom…" value="${esc(F.q || "")}"><div class="btns" style="margin:0"><button type="button" class="ghost sm" id="of-tout">Tout cocher</button><button type="button" class="ghost sm" id="of-rien">Tout décocher</button></div><span></span></div>
        <div class="of-dest">${A.destinataires.map(x => `<label class="ck" data-ofn="${esc((x.nom + " " + (x.ville || "")).toLowerCase())}"><input type="checkbox" data-ofx="${x.id}" ${F.exclus.has(x.id) ? "" : "checked"}><span>${esc(x.nom)} <span class="hint">${esc(x.ville || "ville inconnue")}${x.distance != null ? " · " + String(x.distance).replace(".", ",") + " km" : ""}${(x.deja || []).length ? " · déjà prise le " + x.deja.map(pDay).join(", ") : ""}${x.dispo ? ` · <b style="color:#2F6B45">dispo ${x.dispo} date${x.dispo > 1 ? "s" : ""}</b>` : ""}${x.indispo ? ` · <span style="color:#B23B2E">indispo ${x.indispo} date${x.indispo > 1 ? "s" : ""}</span>` : ""}</span></span></label>`).join("") || `<span class="hint">Personne avec ces critères.</span>`}</div></div>` : ""}
      <div class="btns"><button class="ghost" id="of-x">Fermer</button><button class="ghost" id="of-a">${A ? "Recalculer" : "Voir les destinataires"}</button>${A && A.destinataires.length - F.exclus.size > 0 ? `<button class="primary" id="of-go">Envoyer à ${A.destinataires.length - F.exclus.size} personne${A.destinataires.length - F.exclus.size > 1 ? "s" : ""}</button>` : ""}</div>
      <div class="hint">Les personnes déjà prises sur toutes ces dates ne sont pas dans la liste. Distance à vol d'oiseau entre la commune de la personne et le point d'intervention. Chacun(e) est prévenu(e) par e-mail et se positionne dans son appli, sur la ou les dates qui lui conviennent.</div></div>`;
  }
  const liste = O.map(o => { const ouvert = o.statut === "ouvert";
    const ret = o.oui.filter(c => +c.retenu), retH = ret.length ? `<div class="hint">Retenu(e)${ret.length > 1 ? "s" : ""} : <b>${ret.map(c => esc(c.nom)).join(", ")}</b></div>` : "";
    // Offre close : une seule ligne, le détail n'est plus utile.
    if (!ouvert) return `<div class="of-o of-clos"><span><b>Offre du ${esc(o.created_at.slice(0, 10).split("-").reverse().join("/"))}</b> <span class="hint">· ${o.slots.length} date${o.slots.length > 1 ? "s" : ""} · close${o.clos_le ? " le " + esc(o.clos_le.slice(0, 10).split("-").reverse().join("/")) : ""}</span></span>${retH}</div>`;
    const cand = o.oui.filter(c => !+c.retenu).map(c => { const dispo = o.slots.filter(sl => c.dates.includes(sl.key)), fait = C.filter(s => +s.animatrice_id === +c.animatrice_id);
      return `<div class="of-c"><div><b>${esc(c.nom)}</b> ${+c.retenu ? `<span class="pill p-ok">retenu(e)</span>` : ""}<div class="hint">${[c.distance != null ? String(c.distance).replace(".", ",") + " km" : "", c.telephone || "", "répondu le " + String(c.repondu_le).slice(0, 10).split("-").reverse().join("/")].filter(Boolean).map(esc).join(" · ")}</div></div>
        <div class="of-dates">${dispo.map(sl => { const s = C.find(x => x.key === sl.key) || {}, autre = s.animatrice_id && +s.animatrice_id !== +c.animatrice_id, moi = +s.animatrice_id === +c.animatrice_id;
          return `<label class="ck"><input type="checkbox" data-ofk="${o.id}|${c.animatrice_id}" value="${esc(sl.key)}" ${moi ? "checked disabled" : autre || !ouvert ? "disabled" : ""}><span>${esc(pDay(sl.date))} <span class="hint">${esc(sl.magasin)}</span>${moi ? " ✓" : autre ? ` <span class="hint">(${esc(s.nom || "pris")})</span>` : ""}</span></label>`; }).join("")}</div>
        ${ouvert && dispo.some(sl => { const s = C.find(x => x.key === sl.key) || {}; return !s.animatrice_id; }) ? `<button class="ghost sm" data-ofatt="${o.id}|${c.animatrice_id}">Attribuer les dates cochées</button>` : `<span class="hint">Plus de date libre pour cette personne</span>`}</div>`; }).join("");
    return `<div class="of-o"><div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><div><b>Offre du ${esc(o.created_at.slice(0, 10).split("-").reverse().join("/"))}</b> <span class="hint">· ${o.slots.length} date${o.slots.length > 1 ? "s" : ""} · ${o.rayon ? "moins de " + o.rayon + " km" : "toute la zone"} · ${o.total} destinataire${o.total > 1 ? "s" : ""}</span>
        ${ouvert ? `<span class="pill p-warn">ouverte</span>` : `<span class="pill">close</span>`}</div><div class="hint">${o.oui.length} positionné(e)${o.oui.length > 1 ? "s" : ""} · ${o.non} pas disponible${o.non > 1 ? "s" : ""} · ${o.attente} sans réponse</div></div>
      ${retH}${cand || (ret.length ? "" : `<div class="hint">Personne ne s'est encore positionné.</div>`)}
      ${ouvert ? `<div class="btns"><button class="ghost" data-ofclos="${o.id}">Clore l'offre et remercier les personnes non retenues</button></div>` : ""}</div>`; }).join("");
  return `<div class="card"><h2>Offres de mission</h2><div class="muted">Personne de disponible ? Proposez les dates aux intervenants autour du point d'intervention : chacun(e) se positionne, vous choisissez, les autres reçoivent un petit mot de remerciement.</div>
    ${!F ? `<div class="btns"><button class="primary" id="of-new" ${libres.length ? "" : "disabled"}>Proposer les ${libres.length} date${libres.length > 1 ? "s" : ""} à pourvoir</button></div><div class="hint">Ou cochez des dates dans le tableau, puis « Proposer ces dates ».</div>` : form}
    ${liste}</div>`;
}
// Composition des équipes, jour par jour : rôles (chef d'équipe d'abord), personnes, postes à pourvoir.
function vEquipe(){
  const C = PL.op.creneaux.filter(s => s.statut !== "annulee"), hot = (PL.op.devis || {}).pole === "hot"; if (!C.length) return "";
  const chef = r => /chef|responsable|régisseu/i.test(r || "");
  const J = {}; C.forEach(s => (J[s.date] = J[s.date] || []).push(s));
  const jours = Object.keys(J).sort().map(d => { const L = J[d].slice().sort((a, b) => chef(b.role) - chef(a.role) || (a.role || "").localeCompare(b.role || ""));
    const roles = {}; L.forEach(s => { const r = s.role || s.poste || "Intervenant(e)"; (roles[r] = roles[r] || []).push(s); });
    const sansChef = hot && L.length >= 3 && !L.some(s => chef(s.role));
    return `<div class="eq-j"><div class="eq-d"><b>${esc(pDay(d))}</b> <span class="hint">${L.length} personne${L.length > 1 ? "s" : ""}</span>${sansChef ? ` <span class="pill p-warn">pas de chef d'équipe</span>` : ""}</div>
      ${Object.entries(roles).map(([r, xs]) => `<div class="eq-r"><span class="${chef(r) ? "pill p-ok" : "pill"}">${esc(r)} × ${xs.length}</span> ${xs.map(s => s.animatrice_id ? esc(s.nom || "") : `<span class="hint">à pourvoir</span>`).join(", ")}</div>`).join("")}</div>`; }).join("");
  return `<div class="card"><h2>Composition de l'équipe</h2><div class="muted">Par jour : chaque rôle prévu au devis, avec les personnes affectées.${hot ? " Le chef d'équipe voit son équipe dans son appli et rédige le rapport d'événement." : ""}</div><div class="eq">${jours}</div></div>`;
}
// Mode « par personne » : on choisit la personne, puis on coche ses magasins et ses dates.
function vParPersonne(){
  const C = PL.op.creneaux.filter(s => s.statut !== "annulee"), aid = +PL.pers, occ = PL.op.occupees || {};
  const per = A.L.animatrices.find(a => +a.id === aid), zone = C[0] ? C[0].zone : "";
  const sel = `<label style="max-width:420px">Personne<select id="pp-a">${pOpts(aid, zone).replace('<option value="">À pourvoir</option>', '<option value="">Choisir une personne…</option>')}</select></label>`;
  if (!aid) return sel + `<div class="hint">Choisissez une personne : toutes les dates et tous les magasins de l'opération s'affichent, vous cochez ce que vous lui donnez.</div>`;
  const parDate = {}; for (const s of C) (parDate[s.date] = parDate[s.date] || []).push(s);
  const mien = C.filter(s => +s.animatrice_id === aid);
  const lignes = Object.keys(parDate).sort().map(d => `<div class="pp-row"><div class="pp-d">${esc(pDay(d))}</div><div class="pp-chips">${parDate[d].map(s => {
      const moi = +s.animatrice_id === aid, autre = s.animatrice_id && !moi;
      // Un seul magasin par jour : grisé si elle travaille déjà ce jour-là (ici ou ailleurs) ou si un autre magasin du jour est déjà coché.
      const ailleurs = !moi && ((occ[d] && (occ[d][aid] || []).some(x => x.key !== s.key && !PL.del.has(x.key))) || parDate[d].some(x => x.key !== s.key && ((+x.animatrice_id === aid && !PL.del.has(x.key)) || PL.add.has(x.key))));
      const on = moi ? !PL.del.has(s.key) : PL.add.has(s.key), dis = autre || ailleurs;
      return `<button type="button" class="pp-c${on ? " on" : ""}${moi ? " mine" : ""}${dis ? " dis" : ""}" data-ppk="${esc(s.key)}" ${dis ? "disabled" : ""} title="${esc(autre ? "Déjà à " + (s.nom || "quelqu'un") : ailleurs ? "Déjà prise ce jour-là ailleurs" : moi ? "Cliquez pour lui retirer" : "Cliquez pour lui attribuer")}">${esc(s.magasin || "Point d'intervention")}${autre ? `<i>${esc(s.nom || "")}</i>` : ailleurs ? `<i>un seul magasin par jour</i>` : ""}</button>`; }).join("")}</div></div>`).join("");
  const n = PL.add.size + PL.del.size;
  return sel + `<div class="hint">${[pNom(per), mien.length + " jour" + (mien.length > 1 ? "s" : "") + " sur cette opération", per.telephone, per.email].filter(Boolean).map(esc).join(" · ")}. Noir : ses magasins. Cliquez pour cocher ou décocher. Grisé : déjà pris.</div>
    <div class="pp">${lignes}</div>
    <div class="btns"><button class="ghost" id="pp-x" ${n ? "" : "disabled"}>Annuler les changements</button><button class="primary" id="pp-go" ${n ? "" : "disabled"}>${n ? `Enregistrer (${PL.add.size ? "+" + PL.add.size : ""}${PL.add.size && PL.del.size ? " / " : ""}${PL.del.size ? "−" + PL.del.size : ""})` : "Enregistrer"}</button></div>`;
}
function vPlanning(){
  const O = PL.ops || [];
  const test = PL.ouvert ? `<div class="hint" style="margin-top:8px">Planning ouvert aux intervenants : elles voient leurs missions dans l'appli et reçoivent les e-mails. <button class="ghost" id="pl-ouv" data-v="0">Repasser en test</button></div>`
    : `<div class="plan-test"><b>Mode test.</b> Les intervenants ne voient rien et les e-mails vous arrivent à vous. les testeurs internes (testeurs) voient déjà leur planning dans leur propre appli et reçoivent leurs e-mails. Pour vérifier ce que les autres verront, utilisez « Voir son appli » dans l'onglet Intervenants. <button class="ghost" id="pl-ouv" data-v="1">Ouvrir aux intervenants</button> <button class="ghost" id="pl-purge">Effacer les tests</button>
      ${PL.purge ? `<div class="plan-form" style="margin-top:10px;padding:10px;border-radius:8px;background:#fff"><b>Remise à zéro des essais</b><div class="hint">Efface toutes les attributions, l'historique du planning, les relevés d'heures et les contrats. Les fiches des personnes, les rapports d'animation et les clients ne sont pas touchés.</div>
        <label class="ck"><input type="checkbox" id="pg-docs"><span>Effacer aussi tous les devis et toutes les factures (y compris émises)</span></label>
        <label>Tapez EFFACER pour confirmer<input id="pg-ok" autocomplete="off" style="max-width:200px"></label>
        <div class="btns"><button class="ghost" id="pg-x">Annuler</button><button class="ghost danger" id="pg-go">Tout effacer</button></div></div>` : ""}</div>`;
  const head = `<div class="card accent"><h2>Planning des opérations</h2><div class="muted">Chaque devis est une opération. Chaque date du devis reçoit une personne. Une date annulée ou reportée avec accord du client est justifiée sur la facture.</div>${test}
    ${O.length ? `<label style="margin-top:10px">Opération<select id="pl-op">${O.map(o => `<option value="${o.id}" ${o.id === PL.id ? "selected" : ""}>${esc(o.client)} · devis ${esc(o.numero || "brouillon")} · ${esc(fmtD(o.du))}${o.au !== o.du ? " au " + esc(fmtD(o.au)) : ""} · ${o.pourvus}/${o.n} pourvus${o.ecarts ? " · " + o.ecarts + " écart" + (o.ecarts > 1 ? "s" : "") : ""} (${o.statut === "accepte" ? "accepté" : o.statut === "envoye" ? "envoyé" : "brouillon"})</option>`).join("")}</select></label>` : ""}</div>`;
  if (!PL.op) return head + `<div class="card"><div class="empty">Aucun devis avec des dates. Créez un devis, ses dates apparaîtront ici.</div></div>`;
  const D = PL.op.devis, C = PL.op.creneaux, act = C.filter(s => s.statut !== "annulee");
  const qui = {}; for (const s of act) { const k = s.animatrice_id || 0; (qui[k] = qui[k] || {nom:s.nom || "À pourvoir", n:0, dates:[], info:0}).n++; qui[k].dates.push(s.date); if (s.notifie_le) qui[k].info++; }
  const nomDe = id => { const a = A.L.animatrices.find(x => +x.id === +id); return a ? a.nom + " " + a.prenom : ""; };
  const quiH = Object.entries(qui).sort((a, b) => (+a[0] === 0) - (+b[0] === 0) || nomDe(a[0]).localeCompare(nomDe(b[0]), "fr")).map(([id, q]) => `<div class="plan-qui${+id ? "" : " vide"}"><b>${esc(q.nom)}</b> · ${q.n} jour${q.n > 1 ? "s" : ""}<div class="hint">${[...new Set(q.dates)].map(pDay).join(", ")}</div>${+id ? `<div class="hint" style="color:${q.info ? "#2F6B45" : "var(--terra)"}">${q.info ? "planning reçu" : "pas encore prévenu(e)"}</div>` : ""}${+id ? `<button class="ghost sm" data-pmail="${id}" title="Lui envoyer son planning">✉</button>` : ""}</div>`).join("");
  const F = PL.form;
  const rows = C.map(s => {
    const an = s.statut === "annulee", aj = s.origine === "ajout", k = esc(s.key);
    const etat = an ? `<span class="pill p-bad">annulé</span>${s.remplace_par ? `<div class="hint">reporté au ${esc(pDay(s.remplace_par))}</div>` : `<div class="hint">non facturé</div>`}${s.accord ? `<div class="hint">accord : ${esc(s.accord)}</div>` : ""}${s.motif ? `<div class="hint">${esc(s.motif)}</div>` : ""}`
      : aj ? `<span class="pill p-warn">report</span><div class="hint">remplace le ${esc(pDay(s.remplace))}</div>${s.accord ? `<div class="hint">accord : ${esc(s.accord)}</div>` : ""}` : s.animatrice_id ? (s.absent ? `<span class="pill p-bad">absent(e)</span><div class="hint">non payé, non facturé · remplaçable dans Prise de poste</div>` : `<span class="pill p-ok">pourvu</span>${s.remplace_absent ? `<div class="hint">remplace ${esc(s.remplace_absent)} (absent(e))</div>` : ""}`) : `<span class="pill">à pourvoir</span>`;
    // Même personne sur deux magasins le même jour (saisi avant le blocage) : signalé en rouge, à corriger.
    const dbl = !an && s.animatrice_id && ((PL.op.occupees || {})[s.date] || {})[s.animatrice_id] && PL.op.occupees[s.date][s.animatrice_id].some(x => x.key !== s.key && !(x.fin <= s.debut || x.debut >= s.fin)); // journée partagée : pas de conflit si les heures ne se chevauchent pas
    const pers = an ? `<span class="muted">${esc(s.nom || "")}</span>` : `<select data-paff="${k}" ${dbl ? 'style="border-color:#B23B2E;background:#FBE3DF"' : ""}>${pOpts(s.animatrice_id, s.zone, s)}</select>${dbl ? `<div class="hint" style="color:#B23B2E"><b>Conflit :</b> déjà ailleurs ce jour-là, choisissez une autre personne</div>` : ""}`;
    const acts = s.partie ? `<button class="ghost sm" data-pdep="${s.mission_id}">${s.origine === "partie" ? "Retirer cette partie" : "Annuler le partage"}</button>` : an ? `<button class="ghost sm" data-pret="${k}">Rétablir</button>` : aj ? `<button class="ghost sm" data-pret="${k}">Annuler le report</button>`
      : `<button class="ghost sm" data-pform="dep|${k}">Reporter</button> <button class="ghost sm" data-pform="ann|${k}">Annuler</button>${s.animatrice_id && s.id ? ` <button class="ghost sm" data-ppartage="${s.id}" data-date="${s.date}" title="Demi-journée ailleurs, ou relais par une autre personne">Partager</button>` : ""}`;
    let form = "";
    if (F && F.key === s.key) form = `<tr class="plan-form"><td colspan="6"><div class="row3">
      ${F.t === "dep" ? `<label>Nouvelle date<input type="date" id="pf-date" value="${esc(F.date || "")}"></label>` : ""}
      <label>Accord du client${F.t === "dep" ? " (obligatoire)" : ""}<input id="pf-acc" placeholder="ex. Claire, par e-mail le 8/10" value="${esc(F.accord || "")}"></label>
      <label>Motif<input id="pf-mot" placeholder="ex. magasin fermé, inventaire" value="${esc(F.motif || "")}"></label></div>
      <div class="btns"><button class="ghost" id="pf-x">Fermer</button><button class="primary" id="pf-go">${F.t === "dep" ? "Reporter" : "Annuler cette date"}</button></div>
      <div class="hint">${F.t === "dep" ? "La date d'origine passe en annulée, la nouvelle date garde la même personne. La facture indiquera « annulé, remplacé par… accord… »." : "La date n'est pas facturée. L'écart est indiqué sur la facture."}</div></td></tr>`;
    return `<tr class="${an ? "plan-an" : ""}${aj ? " plan-aj" : ""}">${an ? `<td></td>` : `<td><input type="checkbox" data-psel="${k}" ${PL.sel.has(s.key) ? "checked" : ""}></td>`}
      <td><b>${esc(pDay(s.date))}</b>${s.majore ? ` <span class="pill p-warn" title="Jour férié majoré">×2</span>` : ""}</td><td>${esc(s.magasin || "Point d'intervention à préciser")}<div class="hint">${s.role ? `<b>${esc(s.role)}</b> · ` : ""}${esc(s.poste)} · ${esc(s.debut)} à ${esc(s.fin)}</div></td>
      <td>${pers}</td><td>${etat}</td><td style="white-space:nowrap">${acts}</td></tr>${form}`;
  }).join("");
  const fac = PL.op.facture;
  const facH = `<div class="card"><h2>Facture</h2><div class="muted">La facture reprend le réalisé : dates annulées retirées, reports ajoutés, et la ligne qui justifie l'écart avec le devis.</div>
    <div class="btns">${fac ? `<button class="ghost" id="pl-fopen">Ouvrir la facture${fac.numero ? " n° " + esc(fac.numero) : " (brouillon)"}</button>${fac.numero ? "" : `<button class="primary" id="pl-fact">Mettre à jour d'après le planning</button>`}` : `<button class="primary" id="pl-fnew">Préparer la facture</button>`}</div></div>`;
  const J = PL.op.journal;
  return head + `<div class="card"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap"><div><h2>${esc(D.client)} · devis ${esc(D.numero || "brouillon")} ${pStat(D.statut)}</h2><div class="hint">${esc(D.objet || "")}${D.contact ? " · " + esc(D.contact) : ""}</div></div>
      <button class="ghost" id="pl-mailall">Envoyer à chacun son planning</button></div>
      <h3 style="margin-top:14px">Qui fait quoi</h3><div class="plan-quis">${quiH || `<span class="hint">Personne pour l'instant.</span>`}</div></div>
    <div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>Créneaux du devis (${act.filter(s => s.animatrice_id).length}/${act.length} pourvus)</h2>
        <div class="chips"><button type="button" class="chip" data-plm="creneau" aria-pressed="${PL.mode !== "personne"}">Par date et magasin</button><button type="button" class="chip" data-plm="personne" aria-pressed="${PL.mode === "personne"}">Par personne</button></div></div>
      <label class="ck"><input type="checkbox" id="pl-notif" ${PL.notif ? "checked" : ""}><span>Prévenir par e-mail des changements (seulement les personnes qui ont déjà reçu leur planning)</span></label>
      ${PL.mode === "personne" ? vParPersonne() : `<div class="tablewrap"><table class="plan-t"><tr><th><input type="checkbox" id="pl-all" title="Tout cocher"></th><th>Date</th><th>Point d'intervention</th><th>Personne</th><th>État</th><th></th></tr>${rows}</table></div>`}</div>
    ${vEquipe()}
    ${vOffres()}
    ${facH}
    <div class="card"><h2>Historique</h2>${J.length ? `<div class="plan-log">${J.map(j => `<div><span class="hint">${esc(j.created_at.slice(0, 16).replace(/^(\d+)-(\d+)-(\d+)/, "$3/$2/$1"))} · ${esc(j.par)}</span> ${esc(j.texte)}</div>`).join("")}</div>` : `<div class="empty">Aucun changement pour l'instant.</div>`}</div>
    <div class="pl-bar" ${PL.sel.size ? "" : "hidden"}><span>${PL.sel.size} date${PL.sel.size > 1 ? "s cochées" : " cochée"}</span><select id="pl-bulk">${pOpts("", "")}</select>
      <button class="ghost" id="pl-clear">Décocher</button><button class="ghost" id="pl-prop">Proposer ces dates</button><button class="primary" id="pl-go">Attribuer</button></div>`;
}
function bPlanning(){
  const re = () => go("gestion"), did = () => PL.op.devis.id;
  const prev = r => (r.prevenus && r.prevenus.length ? ". E-mail" + (PL.ouvert ? "" : " de test (reçu par vous)") + " pour : " + r.prevenus.join(", ") : "") + (r.contrats && r.contrats.length ? ". Contrats (onglet Contrats) : " + r.contrats.join(" ; ") : "");
  const msg = r => (prev(r) || ". Pas d'e-mail : la personne n'a pas encore reçu son planning (bouton ✉ ou « Envoyer à chacun son planning »)") + (r.conflits && r.conflits.length ? ". Attention, deux animations le même jour : " + r.conflits.map(fmtD).join(", ") : "");
  if ($("#pl-op")) $("#pl-op").onchange = e => { PL.id = +e.target.value; PL.sel.clear(); PL.form = null; re(); };
  if ($("#pl-ouv")) $("#pl-ouv").onclick = async e => { const b = e.target, v = b.dataset.v === "1";
    if (v && b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer : les intervenants verront leur planning"; return; }
    try { await papi("ouvrir", {ouvert:v ? 1 : 0}); toast(v ? "Planning ouvert aux intervenants" : "Planning repassé en test"); re(); } catch(x) { toast(x.message); } };
  if ($("#pl-purge")) $("#pl-purge").onclick = () => { PL.purge = !PL.purge; re(); };
  if ($("#pg-x")) $("#pg-x").onclick = () => { PL.purge = false; re(); };
  if ($("#pg-go")) $("#pg-go").onclick = async () => { try { const r = await papi("purger", {confirm:$("#pg-ok").value, docs:$("#pg-docs").checked ? 1 : 0});
    PL.purge = false; PL.id = 0; G.docs = null; toast("Effacé : " + r.n.missions + " créneau(x), " + r.n.releves + " relevé(s)" + (r.n.docs ? ", " + r.n.docs + " devis et factures" : "")); re(); } catch(e) { toast(e.message); } };
  if (!PL.op) return;
  $("#pl-notif").onchange = e => PL.notif = e.target.checked;
  document.querySelectorAll("[data-paff]").forEach(s => s.onchange = async () => {
    try { const r = await papi("affecter", {devis_id:did(), keys:[s.dataset.paff], animatrice_id:+s.value || 0, notifier:PL.notif ? 1 : 0}); toast((s.value ? "Attribué" : "Retiré") + msg(r)); re(); } catch(e) { toast(e.message); re(); } });
  const bar = () => { const b = $(".pl-bar"); b.hidden = !PL.sel.size; b.querySelector("span").textContent = PL.sel.size + " date" + (PL.sel.size > 1 ? "s cochées" : " cochée"); };
  document.querySelectorAll("[data-psel]").forEach(c => c.onchange = () => { c.checked ? PL.sel.add(c.dataset.psel) : PL.sel.delete(c.dataset.psel); bar(); });
  if ($("#pl-all")) $("#pl-all").onchange = e => { document.querySelectorAll("[data-psel]").forEach(c => { c.checked = e.target.checked; c.checked ? PL.sel.add(c.dataset.psel) : PL.sel.delete(c.dataset.psel); }); bar(); };
  $("#pl-clear").onclick = () => { PL.sel.clear(); re(); };
  // Mode par personne
  document.querySelectorAll("[data-plm]").forEach(b => b.onclick = () => { PL.mode = b.dataset.plm; PL.add.clear(); PL.del.clear(); PL.sel.clear(); re(); });
  if ($("#pp-a")) $("#pp-a").onchange = e => { PL.pers = +e.target.value || 0; PL.add.clear(); PL.del.clear(); re(); };
  document.querySelectorAll("[data-ppk]").forEach(b => b.onclick = () => { const k = b.dataset.ppk, s = PL.op.creneaux.find(x => x.key === k);
    if (+s.animatrice_id === +PL.pers) PL.del.has(k) ? PL.del.delete(k) : PL.del.add(k); else PL.add.has(k) ? PL.add.delete(k) : PL.add.add(k);
    const y = scrollY; re(); setTimeout(() => scrollTo(0, y), 60); });
  if ($("#pp-x")) $("#pp-x").onclick = () => { PL.add.clear(); PL.del.clear(); re(); };
  if ($("#pp-go")) $("#pp-go").onclick = async () => { const b = $("#pp-go"); b.disabled = true; let msg = "";
    try { if (PL.add.size) { const r = await papi("affecter", {devis_id:did(), keys:[...PL.add], animatrice_id:+PL.pers, notifier:PL.notif ? 1 : 0}); msg += r.n + " attribuée" + (r.n > 1 ? "s" : "") + msg_(r); }
      if (PL.del.size) { const r = await papi("affecter", {devis_id:did(), keys:[...PL.del], animatrice_id:0, notifier:PL.notif ? 1 : 0}); msg += (msg ? ", " : "") + r.n + " retirée" + (r.n > 1 ? "s" : ""); }
      PL.add.clear(); PL.del.clear(); toast(msg || "Rien à changer"); re(); } catch(e) { toast(e.message); b.disabled = false; } };
  const msg_ = r => r.prevenus && r.prevenus.length ? " (e-mail : " + r.prevenus.join(", ") + ")" : "";
  // Offres de mission
  const ofOpen = keys => { PL.of = {keys, rayon:20, sans:true, msg:"", apercu:null, exclus:new Set()}; PL.sel.clear(); re(); };
  if ($("#of-new")) $("#of-new").onclick = () => ofOpen(PL.op.creneaux.filter(s => s.statut !== "annulee" && !s.animatrice_id).map(s => s.key));
  $("#pl-prop").onclick = () => ofOpen([...PL.sel].filter(k => { const s = PL.op.creneaux.find(x => x.key === k); return s && !s.animatrice_id; }));
  const ofKeep = () => { if (!PL.of) return; PL.of.rayon = +$("#of-r").value; PL.of.sans = $("#of-s").checked; PL.of.msg = $("#of-m").value; };
  if ($("#of-x")) $("#of-x").onclick = () => { PL.of = null; re(); };
  if ($("#of-a")) $("#of-a").onclick = async () => { ofKeep(); const F = PL.of; const b = $("#of-a"); b.disabled = true; b.textContent = "Calcul des distances…";
    try { F.apercu = await papi("offre_apercu", {devis_id:did(), keys:F.keys, rayon:F.rayon, sans_adresse:F.sans ? 1 : 0}); F.exclus = new Set(); re(); } catch(e) { toast(e.message); b.disabled = false; } };
  // Sélection des destinataires : sans recharger (liste longue), recherche par nom, tout cocher / tout décocher.
  const ofMaj = () => { const F = PL.of, n = F.apercu.destinataires.length - F.exclus.size, g = $("#of-go"); if (g) { g.textContent = "Envoyer à " + n + " personne" + (n > 1 ? "s" : ""); g.disabled = !n; g.dataset.confirm = ""; } };
  document.querySelectorAll("[data-ofx]").forEach(c => c.onchange = () => { c.checked ? PL.of.exclus.delete(+c.dataset.ofx) : PL.of.exclus.add(+c.dataset.ofx); ofMaj(); });
  const ofFiltre = () => { const q = ($("#of-q").value || "").trim().toLowerCase(); PL.of.q = q; document.querySelectorAll("[data-ofn]").forEach(l => l.hidden = !!q && !l.dataset.ofn.includes(q)); };
  if ($("#of-q")) { $("#of-q").oninput = ofFiltre; ofFiltre(); }
  const ofTous = on => { document.querySelectorAll("[data-ofn]").forEach(l => { if (l.hidden) return; const c = l.querySelector("input"); c.checked = on; on ? PL.of.exclus.delete(+c.dataset.ofx) : PL.of.exclus.add(+c.dataset.ofx); }); ofMaj(); };
  if ($("#of-tout")) $("#of-tout").onclick = () => ofTous(true);
  if ($("#of-rien")) $("#of-rien").onclick = () => ofTous(false);
  if ($("#of-go")) $("#of-go").onclick = async e => { ofKeep(); const F = PL.of, b = e.target; if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer l'envoi"; return; } b.disabled = true;
    try { const r = await papi("offre_envoyer", {devis_id:did(), keys:F.keys, rayon:F.rayon, sans_adresse:F.sans ? 1 : 0, message:F.msg, exclus:[...F.exclus]}); PL.of = null;
      toast("Offre envoyée à " + r.n + " personne" + (r.n > 1 ? "s" : "") + (r.test ? " (e-mails de test reçus par vous)" : "")); re(); } catch(x) { toast(x.message); b.disabled = false; } };
  document.querySelectorAll("[data-ofatt]").forEach(b => b.onclick = async () => { const [oid, aid] = b.dataset.ofatt.split("|");
    const keys = [...document.querySelectorAll(`[data-ofk="${oid}|${aid}"]:checked:not(:disabled)`)].map(i => i.value); if (!keys.length) return toast("Cochez au moins une date.");
    try { const r = await papi("offre_attribuer", {offre_id:+oid, animatrice_id:+aid, keys}); toast(r.n + " date" + (r.n > 1 ? "s attribuées" : " attribuée") + (r.mail ? ", la personne est prévenue" : "") + (r.clos ? ". Toutes les dates sont pourvues : offre close, les autres ont été remercié(e)s." : "")); re(); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-ofclos]").forEach(b => b.onclick = async () => { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer : clore l'offre"; return; }
    try { const r = await papi("offre_clore", {offre_id:+b.dataset.ofclos}); toast("Offre close" + (r.n ? ", " + r.n + " message" + (r.n > 1 ? "s" : "") + " de remerciement envoyé" + (r.n > 1 ? "s" : "") : "")); re(); } catch(e) { toast(e.message); } });
  $("#pl-go").onclick = async () => { try { const r = await papi("affecter", {devis_id:did(), keys:[...PL.sel], animatrice_id:+$("#pl-bulk").value || 0, notifier:PL.notif ? 1 : 0}); PL.sel.clear(); toast(r.n + " date" + (r.n > 1 ? "s mises à jour" : " mise à jour") + msg(r)); re(); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-pform]").forEach(b => b.onclick = () => { const [t, ...k] = b.dataset.pform.split("|"); PL.form = {t, key:k.join("|")}; re(); });
  if ($("#pf-x")) $("#pf-x").onclick = () => { PL.form = null; re(); };
  if ($("#pf-go")) $("#pf-go").onclick = async () => { const F = PL.form; F.accord = $("#pf-acc").value.trim(); F.motif = $("#pf-mot").value.trim(); if ($("#pf-date")) F.date = $("#pf-date").value;
    try { let r; if (F.t === "dep") r = await papi("deplacer", {devis_id:did(), key:F.key, date:F.date, accord:F.accord, motif:F.motif, notifier:PL.notif ? 1 : 0});
      else r = await papi("annuler", {devis_id:did(), key:F.key, accord:F.accord, motif:F.motif, notifier:PL.notif ? 1 : 0});
      toast((F.t === "dep" ? "Date reportée" : "Date annulée") + prev(r)); PL.form = null; re(); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-ppartage]").forEach(b => b.onclick = () => { PP.date = b.dataset.date; PP.partage = +b.dataset.ppartage; G.sub = "poste"; go("gestion"); });
  document.querySelectorAll("[data-pdep]").forEach(b => b.onclick = async () => { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer"; return; }
    try { await gapi("departager", {mission_id:+b.dataset.pdep}); toast("Partage annulé"); PL.op = null; go("gestion"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-pret]").forEach(b => b.onclick = async () => { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer"; return; }
    try { const r = await papi("retablir", {devis_id:did(), key:b.dataset.pret, notifier:PL.notif ? 1 : 0}); toast("Retour en arrière fait" + prev(r)); re(); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-pmail]").forEach(b => b.onclick = async () => { try { const r = await papi("notifier", {animatrice_id:+b.dataset.pmail}); toast(r.n ? "Planning envoyé" + (r.test ? " (à vous, mode test)" : "") : "Pas d'e-mail sur sa fiche"); } catch(e) { toast(e.message); } });
  $("#pl-mailall").onclick = async () => { try { const r = await papi("notifier", {devis_id:did()}); toast(r.n + "/" + r.total + " planning" + (r.total > 1 ? "s envoyés" : " envoyé") + (r.test ? " (à vous, mode test)" : "")); } catch(e) { toast(e.message); } };
  const openF = async id => { G.ed = (await gapi("doc", null, "id=" + id)).doc; G.sub = "factures"; G.mail = null; go("gestion"); };
  if ($("#pl-fnew")) $("#pl-fnew").onclick = async () => { try { const r = await gapi("facture_depuis_devis", {id:did()}); await openF(r.id); } catch(e) { toast(e.message); } };
  if ($("#pl-fact")) $("#pl-fact").onclick = async () => { try { const r = await gapi("facture_depuis_devis", {id:did(), actualiser:1}); toast("Facture mise à jour"); await openF(r.id); } catch(e) { toast(e.message); } };
  if ($("#pl-fopen")) $("#pl-fopen").onclick = async () => { try { await openF(PL.op.facture.id); } catch(e) { toast(e.message); } };
}
