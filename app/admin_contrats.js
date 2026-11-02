// Contrats de travail : un par personne et par opération, construit depuis le planning. L'admin l'envoie quand il veut ; après signature, un changement de dates donne un avenant.
const CT = { list:[], ouvert:true, op:"", sel:null, C:null, vue:"", H:null, prop:null, calc:null, fin:null };
const ctPill = e => ({aucun:`<span class="pill">aucune date</span>`, a_envoyer:`<span class="pill">à envoyer</span>`, envoye:`<span class="pill p-warn">envoyé, à signer</span>`,
  a_mettre_a_jour:`<span class="pill p-bad">planning changé, à renvoyer</span>`, signe:`<span class="pill p-ok">signé</span>`, avenant:`<span class="pill p-bad">avenant à envoyer</span>`})[e] || "";
// DPAE : faite dans le TESE avant la 1re journée (au plus tôt 8 jours avant).
const dpPill = D => { if (!D) return ""; const j = D.jours, quand = j == null ? "" : j < 0 ? "commencé" : j === 0 ? "aujourd'hui" : "dans " + j + " j";
  return ({ok:`<span class="pill p-ok">DPAE faite</span>`, sans:"", retard:`<span class="pill p-bad">DPAE en retard</span>`, urgent:`<span class="pill p-bad">DPAE à faire · ${quand}</span>`,
    refaire:`<span class="pill p-warn">date avancée : DPAE à refaire</span>`, possible:`<span class="pill p-warn">DPAE à faire · ${quand}</span>`, trop_tot:`<span class="pill">DPAE : pas avant J-8</span>`})[D.etat] || ""; };
const ctD = iso => iso ? iso.slice(8, 10) + "/" + iso.slice(5, 7) + "/" + iso.slice(0, 4) : "";
const ctEur = v => (+v).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €";
async function loadContrats(){
  if (CT.vue === "cdii") { CT.H = await papi("cdii"); return; }
  const r = await papi("contrats"); CT.list = r.contrats; CT.ouvert = r.ouvert;
  CT.C = CT.sel ? await papi("contrat", null, "animatrice_id=" + CT.sel.a + "&devis_id=" + CT.sel.d) : null;
  CT.fin = null; if (CT.C && CT.C.versions.length && CT.C.lignes.length && CT.C.lignes[CT.C.lignes.length - 1].date < new Date().toISOString().slice(0, 10))
    CT.fin = (await papi("fin_docs", null, "animatrice_id=" + CT.sel.a + "&devis_id=" + CT.sel.d).catch(() => ({}))).fin || null;
}
const ctOnglets = () => `<div class="tabs" style="margin-bottom:12px"><button data-ctv="" ${CT.vue ? "" : 'aria-current="page"'}>Contrats</button><button data-ctv="cdii" ${CT.vue === "cdii" ? 'aria-current="page"' : ""}>Règle des 500 heures (CDII)</button></div>`;
function vContrats(){
  if (CT.vue === "cdii") return ctOnglets() + vCdii();
  if (CT.C) return vContrat();
  const ops = [...new Map(CT.list.map(c => [c.devis_id, c.client + " · " + c.operation])).entries()];
  const L = CT.list.filter(c => !CT.op || c.devis_id === +CT.op);
  const n = k => CT.list.filter(c => c.etat === k).length;
  const alerte = CT.list.filter(c => c.dpae && ["retard", "urgent"].includes(c.dpae.etat) || c.dpae && c.dpae.etat === "refaire");
  return ctOnglets() + `<div class="card accent"><h2>Contrats de travail</h2><div class="muted">Un contrat par personne et par opération, rempli automatiquement avec ses dates du planning. Vous l'envoyez quand vous voulez : il se signe dans l'appli comme le relevé d'heures. Avant l'envoi, les dates suivent le planning. Après signature, tout changement de date donne un avenant à signer.</div>
      ${alerte.length ? `<div class="plan-test" style="background:#FBE3DF;border-color:#E9B4AA"><b>DPAE à faire en urgence : ${alerte.length}</b><br>${alerte.map(c => esc(c.nom) + " (" + esc(c.client) + ", 1re journée le " + esc(ctD(c.dpae.premiere)) + ")").join("<br>")}</div>` : ""}
      ${CT.ouvert ? "" : `<div class="plan-test"><b>Mode test.</b> Les e-mails vous arrivent à vous, sauf les testeurs internes (testeurs) qui reçoivent et signent dans leur propre appli.</div>`}
      <div class="row3"><label>Opération<select id="ct-op"><option value="">Toutes</option>${ops.map(([id, t]) => `<option value="${id}" ${+CT.op === id ? "selected" : ""}>${esc(t)}</option>`).join("")}</select></label>
        <div class="hint" style="align-self:end">${n("a_envoyer")} à envoyer · ${n("envoye")} en attente de signature · ${n("a_mettre_a_jour") + n("avenant")} à renvoyer ou avenant · ${n("signe")} signé${n("signe") > 1 ? "s" : ""}</div></div></div>
    <div class="card">${L.length ? `<div class="tablewrap"><table><tr><th>Personne</th><th>Opération</th><th>Dates</th><th class="num">Jours</th><th>N°</th><th>État</th><th>DPAE</th><th>Fin de contrat</th></tr>${L.map(c => `<tr class="click" data-cta="${c.animatrice_id}|${c.devis_id}">
      <td><b>${esc(c.nom)}</b>${c.manque.length ? `<div class="hint" style="color:var(--terra)">Manque : ${esc(c.manque.join(", "))}</div>` : ""}</td><td>${esc(c.client)}<div class="hint">${esc(c.operation)}</div></td>
      <td>${c.du ? esc(ctD(c.du)) + (c.au !== c.du ? " au " + esc(ctD(c.au)) : "") : ""}</td><td class="num">${c.jours}</td><td>${c.numero ? c.numero + (c.avenants ? ` <span class="hint">+${c.avenants} av.</span>` : "") : ""}</td><td>${ctPill(c.etat)}</td><td>${dpPill(c.dpae)}</td>
      <td>${c.termine ? (c.ft_le ? `<span class="pill p-ok">attestation FT faite</span><div class="hint">le ${esc(ctD(c.ft_le))}</div>` : `<span class="pill p-bad">attestation France Travail à faire</span><div><button class="ghost sm" data-ctft="${c.animatrice_id}|${c.devis_id}" data-stop>Faite sur France Travail</button></div>`) : ""}</td></tr>`).join("")}</table></div>
      <div class="hint" style="margin-top:8px">Fin de contrat : établissez l'attestation France Travail sur votre espace employeur France Travail, puis cliquez « Faite sur France Travail ». Déposez ensuite le PDF dans Documents (type « Attestation France Travail ») : la personne le retrouve dans son appli et reçoit une notification.</div>`
      : `<div class="empty">Aucun contrat : ils apparaissent dès qu'une date est attribuée à une personne dans Planning.</div>`}</div>`;
}
function vContrat(){
  const C = CT.C, S = C.salarie, P = C.params, L = C.lignes, last = C.versions[C.versions.length - 1];
  const f = (k, lab, type) => `<label>${lab}<input data-cs="${k}" ${type ? `type="${type}"` : ""} value="${esc(S[k] || "")}"></label>`;
  const nextAv = last ? (last.statut === "envoye" ? null : C.versions.length) : null;
  const btn = {a_envoyer:"Envoyer le contrat", a_mettre_a_jour:"Renvoyer le contrat mis à jour", envoye:"Renvoyer l'e-mail", avenant:"Envoyer l'avenant n°" + (nextAv || 1), signe:"", aucun:""}[C.etat];
  const tot = L.reduce((a, l) => a + l.salaire, 0), th = L.reduce((a, l) => a + l.heures, 0);
  return `<div class="card accent"><div class="btns" style="margin:0"><button class="ghost" id="ct-back">‹ Tous les contrats</button></div>
      <h2>${esc(S.nom + " " + S.prenom)} · ${esc(P.client)}</h2><div class="hint">${esc(P.operation)} · devis ${esc(C.devis.numero)}</div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">${ctPill(C.etat)}${last ? `<span class="hint">Dernier document : ${esc(last.titre)}</span>` : ""}</div>
      ${C.manque.length ? `<div class="plan-test">Il manque sur sa fiche : <b>${esc(C.manque.join(", "))}</b>. Complétez ci-dessous avant d'envoyer, ou demandez-lui de remplir « Ma fiche » dans l'appli.</div>` : ""}
      ${C.adresse_changee ? `<div class="plan-test"><b>Adresse changée depuis l'envoi du contrat</b> : ${esc(C.adresse_changee.avant)} → <b>${esc(C.adresse_changee.apres)}</b>. Les km sont recalculés depuis la nouvelle adresse (paye et marges). ${["avenant", "a_mettre_a_jour"].includes(C.etat) ? "Envoyez " + (C.etat === "avenant" ? "l'avenant" : "le contrat mis à jour") + " pour qu'il porte la bonne adresse." : ""}</div>` : ""}
      ${C.etat === "avenant" && !C.adresse_changee ? `<div class="plan-test">Le planning a changé depuis la signature. Envoyez l'avenant : il liste les journées retirées et ajoutées, et reprend le nouveau tableau des horaires.</div>` : ""}
      ${C.etat === "a_mettre_a_jour" ? `<div class="plan-test">Le planning a changé depuis l'envoi, et le contrat n'est pas encore signé : renvoyez-le, la version mise à jour remplace l'ancienne.</div>` : ""}
      <div class="btns">${btn ? `<button class="primary" id="ct-send">${esc(btn)}</button>` : ""}<button class="ghost" id="ct-apercu">${C.etat === "signe" || C.etat === "envoye" ? "Voir le PDF" : "Aperçu du PDF"}</button></div></div>
    ${(D => `<div class="card"><h2>DPAE</h2><div class="muted">À faire dans le TESE avant la 1re journée${D.premiere ? " (le " + esc(ctD(D.premiere)) + ")" : ""}, au plus tôt 8 jours avant. Puis notez-la ici.</div>
      <div>${dpPill(D)}${D.fait_le ? ` <span class="hint">faite le ${esc(ctD(D.fait_le))}${D.numero ? " · n° " + esc(D.numero) : ""}${D.pour_le ? " · embauche déclarée au " + esc(ctD(D.pour_le)) : ""} · ${esc(D.par)}</span>` : ""}</div>
      ${D.avancee ? `<div class="plan-test">La 1re journée a été avancée au ${esc(ctD(D.premiere))} : faites une nouvelle DPAE pour cette date.</div>` : ""}
      <div class="row3"><label>DPAE faite le<input type="date" id="dp-le" value="${D.fait_le && !D.avancee ? D.fait_le : new Date().toISOString().slice(0, 10)}"></label><label>Date d'embauche déclarée<input type="date" id="dp-pour" value="${D.premiere || ""}"></label><label>N° d'accusé de réception <span class="hint">facultatif</span><input id="dp-num" value="${esc(D.avancee ? "" : D.numero)}"></label></div>
      <div class="btns"><button class="primary" id="dp-ok">${D.fait_le && !D.avancee ? "Mettre à jour" : "DPAE faite"}</button>${D.fait_le ? `<button class="ghost" id="dp-del">Retirer</button>` : ""}</div></div>`)(C.dpae)}
    <div class="card"><h2>Journées (${new Set(L.map(l => l.date)).size} jours · ${String(th.toFixed(2)).replace(".", ",")} h · ${ctEur(tot)} brut)</h2>
      ${L.length ? `<div class="tablewrap"><table><tr><th>Jour</th><th>Point d'intervention</th><th>Horaires</th><th>Pause</th><th class="num">Heures</th><th class="num">Salaire brut</th></tr>${L.map(l => `<tr><td>${esc(ctD(l.date))}${l.maj ? ` <span class="pill p-warn">${esc(l.maj_txt || "+100 %")}</span>` : ""}</td><td>${esc(l.magasin)}</td><td>${esc(l.debut)} à ${esc(l.fin)}</td><td>${l.pause ? l.pause + " min" : ""}</td><td class="num">${String(l.heures).replace(".", ",")}</td><td class="num">${ctEur(l.salaire)}</td></tr>`).join("")}</table></div>`
        : `<div class="empty">Plus aucune date sur cette opération.</div>`}
      <div class="hint">Les dates viennent du planning : pour les changer, passez par Planning.</div></div>
    ${vFin()}
    ${ctTrajets(C)}
    <div class="card"><h2>Salarié(e)</h2>
      <div class="row3"><label>Civilité<select data-cs="civilite"><option value="">—</option>${["Mme", "M."].map(v => `<option ${S.civilite === v ? "selected" : ""}>${v}</option>`).join("")}</select></label>${natField(S.nationalite || "", "data-cnat")}${secuField(S.secu || "", "data-csecu")}</div>
      <div class="row3">${f("adresse", "Adresse")}${f("code_postal", "Code postal")}${f("ville", "Ville")}</div>
      <div class="row3">${f("naissance_date", "Date de naissance", "date")}${f("naissance_lieu", "Lieu de naissance")}<label>Téléphone<input value="${esc(S.telephone)}" disabled></label></div>
      <h2 style="margin-top:6px">Conditions</h2>
      <div class="row3"><label>Taux horaire brut (€)<input id="ct-taux" value="${String(P.taux).replace(".", ",")}"></label><label>Période d'essai (jours)<input id="ct-essai" type="number" min="0" max="30" value="${P.essai}"></label><label>Client<input id="ct-client" value="${esc(P.client)}"></label></div>
      <div class="row3"><label>Coefficient<input id="ct-coef" inputmode="numeric" value="${esc(P.coef)}" data-auto="${esc((P.coef_regle || {}).coef || "")}"></label>
        <div class="hint" style="grid-column:span 2;align-self:end">${P.coef_regle && P.coef_regle.entree ? `1re mission chez ${AG} le ${esc(ctD(P.coef_regle.entree))} : coefficient débutant avant le ${esc(ctD(P.coef_regle.passage))}, confirmé à partir de cette date (6 mois jour pour jour). Calculé : <b>${esc(P.coef_regle.coef)}</b>.` : `Personne déjà dans l'équipe avant la règle des 6 mois : coefficient confirmé (<b>${esc((P.coef_regle || {}).coef || P.coef)}</b>). Modifiable ici pour ce contrat.`}</div></div>
      <label>Nom de l'opération<input id="ct-opn" value="${esc(P.operation)}"></label>
      <h2 style="margin-top:10px">Adresse de départ pour cette mission</h2>
      <div class="hint">Laissez vide pour partir du domicile habituel. Remplissez si la personne part d'ailleurs pour cette mission (famille, logement temporaire) : les km et l'indemnité sont calculés depuis cette adresse, et le contrat la précise, l'adresse habituelle restant celle du contrat.</div>
      <div class="row3">${[["adresse", "Adresse"], ["code_postal", "Code postal"], ["ville", "Ville"]].map(([k, lab]) => `<label>${lab}<input data-cdp="${k}" value="${esc((P.depart || {})[k] || "")}" placeholder="${esc(S[k] || "")}"></label>`).join("")}</div>
      <div class="btns"><button class="ghost" id="ct-save">Enregistrer</button></div>
      <div class="hint">L'identité est aussi enregistrée sur sa fiche. Les conditions ne concernent que ce contrat. Un contrat déjà envoyé n'est modifié qu'au prochain envoi.</div></div>
    ${C.versions.length ? `<div class="card"><h2>Documents</h2><div class="tablewrap"><table><tr><th>Document</th><th>Envoyé</th><th>État</th><th></th></tr>${C.versions.slice().reverse().map(v => `<tr><td><b>${esc(v.titre)}</b><div class="hint">${v.jours} journée${v.jours > 1 ? "s" : ""}</div></td>
      <td>${v.envoye_le ? esc(ctD(v.envoye_le.slice(0, 10))) + `<div class="hint">${esc(v.envoye_par)}</div>` : ""}</td><td>${v.statut === "signe" ? `<span class="pill p-ok">signé</span>${v.signe_test ? ` <span class="pill">test</span>` : ""}<div class="hint">${esc(v.signe_nom)}, ${esc(ctD(v.signe_le.slice(0, 10)))}</div>${v.sha ? `<div class="hint" title="Empreinte SHA-256 du PDF signé : ${esc(v.sha)}">PDF figé · ${esc(v.sha.slice(0, 12))}…</div>` : ""}` : `<span class="pill p-warn">à signer</span>`}</td>
      <td><button class="ghost sm" data-ctpdf="${v.id}">PDF</button></td></tr>`).join("")}</table></div>
      <div class="plan-log" style="margin-top:8px">${C.versions.flatMap(v => v.journal).sort((a, b) => b.le.localeCompare(a.le)).map(j => `<div><span class="hint">${esc(j.le.slice(0, 16).replace(/^(\d+)-(\d+)-(\d+)/, "$3/$2/$1"))}</span> ${esc(j.texte)}</div>`).join("")}</div></div>` : ""}`;
}
// Trajets et frais estimés (km aller-retour depuis l'adresse de départ, indemnité, repas).
function ctTrajets(C){
  const T = C.trajets; if (!T || !C.lignes.length) return "";
  const tc = T.mode === "tc";
  const choix = `<div class="ct-mode"><label class="ck"><input type="radio" name="ct-tr" value="voiture" ${tc ? "" : "checked"}><span>Véhicule personnel : indemnités kilométriques</span></label>
    <label class="ck"><input type="radio" name="ct-tr" value="tc" ${tc ? "checked" : ""}><span>Transports en commun : une somme par journée</span></label>
    <label class="ct-tcj" ${tc ? "" : "hidden"}>Montant par journée, aller-retour (€)<input id="ct-tcj" inputmode="decimal" value="${tc && T.tc_jour ? (+T.tc_jour).toFixed(2).replace(".", ",") : ""}" placeholder="Ex. 4,00"></label></div>`;
  if (tc) return `<div class="card"><h2>Trajets et frais estimés</h2>${choix}
    <div class="muted">Départ : <b>${esc(T.depart || "adresse à compléter")}</b> ${T.perso ? `<span class="pill p-warn">adresse propre à cette mission</span>` : `<span class="hint">(domicile habituel)</span>`}</div>
    <div class="tablewrap"><table><tr><th>Point d'intervention</th><th class="num">Transport par jour</th><th class="num">Jours</th><th class="num">Total</th></tr>
      ${T.lignes.map(t => `<tr><td>${esc(t.magasin)}</td><td class="num">${t.jour == null ? "à préciser" : ctEur(t.jour)}</td><td class="num">${t.jours}</td><td class="num">${t.total == null ? "" : ctEur(t.total)}</td></tr>`).join("")}
      <tr><td colspan="2">Repas (${ctEur(T.repas)} par journée de plus de 6 h)</td><td class="num">${T.repas_jours}</td><td class="num">${ctEur(T.repas_eur)}</td></tr>
      <tr><td colspan="3"><b>Total estimé des frais</b></td><td class="num"><b>${ctEur(T.total)}</b></td></tr></table></div>
    ${T.incomplet ? `<div class="plan-test">Indiquez le montant par journée.</div>` : ""}
    <div class="btns"><button class="ghost" id="ct-km">Enregistrer</button></div>
    <div class="hint">La personne voit ce montant dans son planning (« Indemnité de transport en commun ») au lieu des km. Le contrat ou l'avenant le reprend à l'envoi.</div></div>`;
  const km = v => v == null ? "à préciser" : Math.round(v).toLocaleString("fr-FR") + " km";
  return `<div class="card"><h2>Trajets et frais estimés</h2>${choix}
    <div class="muted">Départ : <b>${esc(T.depart || "adresse à compléter")}</b> ${T.perso ? `<span class="pill p-warn">adresse propre à cette mission</span>` : `<span class="hint">(domicile habituel)</span>`}</div>
    <div class="tablewrap"><table><tr><th>Point d'intervention</th><th class="num">Km A/R par jour (Mappy)</th><th class="num">Indemnité par jour</th><th class="num">Jours</th><th class="num">Total</th></tr>
      ${T.lignes.map(t => t.circuit ? `<tr><td>${esc(t.magasin)}<div class="hint">trajet réel ${km(t.km_calc)} au lieu de deux allers-retours</div></td><td class="num">${String(t.km_ar).replace(".", ",")} km</td><td class="num">${ctEur(t.jour)}</td><td class="num">1</td><td class="num">${ctEur(t.total)}</td></tr>` : `<tr><td>${esc(t.magasin)}</td><td class="num"><input data-kmm="${esc(t.magasin)}" value="${t.manuel ? String(t.km_ar).replace(".", ",") : ""}" placeholder="${t.km_calc == null ? "km" : String(Math.round(t.km_calc))}" style="width:80px;text-align:right" inputmode="decimal"> km${t.manuel ? `<div class="hint">calculé : ${km(t.km_calc)}</div>` : ""}</td><td class="num">${t.jour == null ? "" : ctEur(t.jour)}</td><td class="num">${t.jours}</td><td class="num">${t.total == null ? "" : ctEur(t.total)}</td></tr>${t.anormal ? `<tr><td colspan="5"><div class="plan-test" style="margin:0"><b>Distance anormale vers ${esc(t.magasin)}</b> : ${esc(t.anormal)}${t.km_calc != null ? " (calcul : " + km(t.km_calc) + ")" : ""}. Non comptée : vérifiez l'adresse de la personne, ou saisissez les km Mappy si la distance est réelle.</div></td></tr>` : ""}`).join("")}
      <tr><td colspan="3">Repas (${ctEur(T.repas)} par journée de plus de 6 h)</td><td class="num">${T.repas_jours}</td><td class="num">${ctEur(T.repas_eur)}</td></tr>
      <tr><td colspan="4"><b>Total estimé des frais</b></td><td class="num"><b>${ctEur(T.total)}</b></td></tr></table></div>
    ${T.incomplet ? `<div class="plan-test">Distance inconnue pour au moins un point d'intervention : vérifiez l'adresse de départ et celle du point d'intervention (Points d'intervention).</div>` : ""}
    <div class="btns"><a class="btn ghost" href="https://fr.mappy.com/itineraire" target="_blank" rel="noopener">Vérifier sur Mappy</a><button class="ghost" id="ct-km">Enregistrer les km</button></div>
    <div class="hint">Calcul automatique : itinéraire routier le plus court. Pour retenir la distance Mappy, ouvrez Mappy (départ : l'adresse ci-dessus, arrivée : le point d'intervention, option « le plus court »), puis saisissez les km aller-retour. Laissez vide pour garder le calcul automatique. Repris tel quel dans le contrat ou l'avenant au moment de l'envoi.${T.approx ? " Une des adresses est approximative : la distance calculée est indicative." : ""}</div></div>`;
}
function bContrats(){
  document.querySelectorAll("[data-ctv]").forEach(b => b.onclick = () => { CT.vue = b.dataset.ctv; CT.sel = null; CT.C = null; CT.prop = null; go("gestion"); });
  if (CT.vue === "cdii") return bCdii();
  // Fin de contrat : net, mutuelle et prévoyance enregistrés avant chaque document (le reçu exige le net, le certificat l'affiliation).
  const finSave = () => papi("fin_solde_net", {animatrice_id:CT.sel.a, devis_id:CT.sel.d, net:$("#fin-net").value, mutuelle:$("#fin-mut").value, prevoyance:$("#fin-prev").value});
  document.querySelectorAll("[data-fdoc]").forEach(b => b.onclick = async () => { const k = b.dataset.fdoc;
    if (k === "fin_solde" && !$("#fin-net").value.trim()) { $("#fin-net").focus(); return toast("Indiquez d'abord le net versé (bulletin de paie) : il figure en chiffres et en lettres sur le reçu."); }
    if (k === "fin_certificat" && (!$("#fin-mut").value || !$("#fin-prev").value)) { ($("#fin-mut").value ? $("#fin-prev") : $("#fin-mut")).focus(); return toast("Indiquez si la personne était affiliée à la mutuelle et à la prévoyance : la mention de portabilité en dépend."); }
    const w = window.open("about:blank", "_blank");
    try { await finSave(); if (w) w.location = "planning_api.php?action=" + k + "&animatrice_id=" + CT.sel.a + "&devis_id=" + CT.sel.d; } catch(e) { if (w) w.close(); toast(e.message); } });
  if ($("#fin-net-ok")) $("#fin-net-ok").onclick = async () => { try { await finSave(); toast("Enregistré"); go("gestion"); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-ctft]").forEach(b => b.onclick = async e => { e.stopPropagation(); const [a, d] = b.dataset.ctft.split("|").map(Number);
    try { await gapi("fin_ft", {animatrice_id:a, devis_id:d}); toast("Attestation France Travail notée. Pensez à déposer le PDF dans ses documents."); go("gestion"); } catch(x) { toast(x.message); } });
  const re = () => go("gestion");
  if (!CT.C) {
    $("#ct-op").onchange = e => { CT.op = e.target.value; re(); };
    document.querySelectorAll("[data-cta]").forEach(tr => tr.onclick = () => { const [a, d] = tr.dataset.cta.split("|"); CT.sel = {a:+a, d:+d}; re(); }); return;
  }
  const q = "animatrice_id=" + CT.sel.a + "&devis_id=" + CT.sel.d;
  $("#ct-back").onclick = () => { CT.sel = null; CT.C = null; re(); };
  const X = {nationalite:CT.C.salarie.nationalite || "", secu:CT.C.salarie.secu || ""};
  natBind("data-cnat", v => X.nationalite = v);
  const civ = () => (document.querySelector('[data-cs="civilite"]') || {}).value, nai = () => (document.querySelector('[data-cs="naissance_date"]') || {}).value;
  const reS = secuBind("data-csecu", civ, nai, v => X.secu = v);
  document.querySelectorAll('[data-cs="civilite"],[data-cs="naissance_date"]').forEach(el => el.addEventListener("change", () => reS && reS()));
  const save = async () => { const sal = {nationalite:X.nationalite, secu:X.secu}; document.querySelectorAll("[data-cs]").forEach(i => sal[i.dataset.cs] = i.value);
    const depart = {}; document.querySelectorAll("[data-cdp]").forEach(i => depart[i.dataset.cdp] = i.value.trim());
    let km_man; if (document.querySelector("[data-kmm]")) { km_man = {}; document.querySelectorAll("[data-kmm]").forEach(i => { if (i.value.trim()) km_man[i.dataset.kmm] = i.value.trim(); }); } // absent en mode transports en commun : on garde les km déjà saisis
    const trm = document.querySelector('input[name="ct-tr"]:checked'), transport = trm ? trm.value : "voiture", tc_jour = $("#ct-tcj") ? $("#ct-tcj").value : "";
    await papi("contrat_save", {animatrice_id:CT.sel.a, devis_id:CT.sel.d, salarie:sal, taux:$("#ct-taux").value, essai:$("#ct-essai").value, client:$("#ct-client").value, operation:$("#ct-opn").value, coef:$("#ct-coef").value.trim() === $("#ct-coef").dataset.auto ? "" : $("#ct-coef").value.trim(), depart, km_man, transport, tc_jour}); };
  document.querySelectorAll('input[name="ct-tr"]').forEach(r => r.onchange = () => { const l = document.querySelector(".ct-tcj"); if (l) l.hidden = r.value !== "tc" || !r.checked; if (r.value === "voiture" && r.checked) l.hidden = true; });
  if ($("#ct-km")) $("#ct-km").onclick = async () => { try { await save(); toast("Km enregistrés"); re(); } catch(e) { toast(e.message); } };
  $("#ct-save").onclick = async () => { try { await save(); toast("Enregistré"); re(); } catch(e) { toast(e.message); } };
  $("#ct-apercu").onclick = async () => { try { if (!["signe", "envoye"].includes(CT.C.etat)) await save(); const v = CT.C.versions[CT.C.versions.length - 1];
    window.open("planning_api.php?action=contrat_pdf&" + (["signe", "envoye"].includes(CT.C.etat) && v ? "id=" + v.id : q), "_blank"); } catch(e) { toast(e.message); } };
  $("#dp-ok").onclick = async () => { try { await papi("contrat_dpae", {animatrice_id:CT.sel.a, devis_id:CT.sel.d, fait_le:$("#dp-le").value, pour_le:$("#dp-pour").value, numero:$("#dp-num").value}); toast("DPAE enregistrée"); re(); } catch(e) { toast(e.message); } };
  if ($("#dp-del")) $("#dp-del").onclick = async e => { if (e.target.dataset.confirm !== "1") { e.target.dataset.confirm = "1"; e.target.textContent = "Confirmer"; return; }
    try { await papi("contrat_dpae", {animatrice_id:CT.sel.a, devis_id:CT.sel.d, retirer:1}); re(); } catch(x) { toast(x.message); } };
  document.querySelectorAll("[data-ctpdf]").forEach(b => b.onclick = () => window.open("planning_api.php?action=contrat_pdf&id=" + b.dataset.ctpdf, "_blank"));
  if ($("#ct-send")) $("#ct-send").onclick = async e => { const b = e.target;
    if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = (CT.C.manque.length ? "Il manque des informations. " : "") + "Confirmer l'envoi"; return; }
    b.disabled = true;
    try { if (CT.C.etat !== "envoye") await save(); const r = await papi("contrat_envoyer", {animatrice_id:CT.sel.a, devis_id:CT.sel.d});
      toast(r.titre + " envoyé" + (r.prevenus && r.prevenus.length ? ". E-mail" + (r.test ? " de test (reçu par vous)" : "") + " pour : " + r.prevenus.join(", ") : "")); re(); } catch(x) { toast(x.message); b.disabled = false; } };
}

/* ---------- Fin de contrat ---------- */
function vFin(){
  const X = CT.fin; if (!X) return "";
  const n = v => String(v).replace(".", ",");
  return `<div class="card"><h2>Fin de contrat</h2><div class="muted">Contrat terminé le ${esc(ctD(X.au))}. Documents à remettre : certificat de travail, reçu pour solde de tout compte (avec le dernier bulletin), attestation France Travail (sur votre espace employeur France Travail).</div>
    <div class="row3"><div><b>Certificat de travail</b><div class="hint">Du ${esc(ctD(X.du))} au ${esc(ctD(X.au))}, ${esc(X.emploi)}, ${esc(X.type)}. Le maintien gratuit des garanties (portabilité) n'est mentionné que pour celles auxquelles la personne était affiliée.</div>
        <label>Mutuelle santé de l'entreprise<select id="fin-mut"><option value="">Choisir…</option><option value="affilie" ${X.mutuelle === "affilie" ? "selected" : ""}>Affilié(e)</option><option value="dispense" ${X.mutuelle === "dispense" ? "selected" : ""}>Dispensé(e), non affilié(e)</option></select></label>
        <label>Prévoyance de l'entreprise<select id="fin-prev"><option value="">Choisir…</option><option value="affilie" ${X.prevoyance === "affilie" ? "selected" : ""}>Affilié(e)</option><option value="dispense" ${X.prevoyance === "dispense" ? "selected" : ""}>Non affilié(e)</option></select></label>
        <div class="btns"><button class="ghost" data-fdoc="fin_certificat">Certificat (PDF)</button></div></div>
      <div><b>Solde de tout compte</b><div class="hint">Total brut ${ctEur(X.total_brut)} (salaire ${ctEur(X.brut)}${X.preca ? ", fin de contrat " + ctEur(X.preca) : ""}, congés ${ctEur(X.cp)})${X.frais && (X.frais.repas + X.frais.km + X.frais.autres) ? `, frais ${ctEur(X.frais.repas + X.frais.km + X.frais.autres)}` : ""}. Le net du bulletin figure en chiffres et en lettres sur le reçu.</div>
        <label>Net versé (€)<input id="fin-net" inputmode="decimal" value="${X.net == null ? "" : n(X.net)}" placeholder="d'après le bulletin"></label><div class="btns"><button class="ghost" id="fin-net-ok">Enregistrer</button><button class="ghost" data-fdoc="fin_solde">Reçu (PDF)</button></div></div>
      <div><b>Attestation France Travail</b><div class="hint">À établir sur votre espace employeur France Travail (ou par la DSN si votre logiciel de paye la fait). Données utiles :</div>
        <div class="hint" style="line-height:1.6">Motif : ${esc(X.motif)}<br>Période : ${esc(ctD(X.du))} au ${esc(ctD(X.au))} (${X.jours} jour${X.jours > 1 ? "s" : ""})<br>Heures travaillées : ${n(X.heures)} h<br>Salaire brut : ${ctEur(X.brut)}${X.preca ? "<br>Indemnité de fin de contrat : " + ctEur(X.preca) : ""}<br>Congés payés : ${ctEur(X.cp)}${X.premiere_entree ? "<br>1re mission chez " + esc(AG) + " : " + esc(ctD(X.premiere_entree)) : ""}</div></div></div></div>`;
}
/* ---------- Règle des 500 heures (CIDD → CDI intermittent) ---------- */
const CDII_ETAT = {retard:`<span class="pill p-bad">en retard</span>`, a_proposer:`<span class="pill p-bad">à proposer</span>`, propose:`<span class="pill p-warn">proposition remise</span>`, prevu:`<span class="pill p-warn">500 h prévues au planning</span>`,
  approche:`<span class="pill">approche des 500 h</span>`, cdii:`<span class="pill p-ok">en CDII</span>`, sous:""};
function vCdii(){
  const H = CT.H || {suivi:[]}, L = H.suivi.filter(x => CT.tous || x.etat !== "sous"), n = v => String(v).replace(".", ",");
  const urg = H.suivi.filter(x => ["retard", "a_proposer"].includes(x.etat));
  return `<div class="card accent"><h2>Règle des 500 heures</h2>
    <div class="muted">Une personne en CIDD qui a travaillé <b>500 heures ou plus sur les 12 derniers mois</b>, au fil de plusieurs interventions, doit recevoir une proposition de contrat de travail intermittent à durée indéterminée (CDII) <b>dans les 2 mois</b>, par recommandé avec AR ou remise en main propre contre décharge. Elle a <b>15 jours calendaires</b> pour répondre, son silence vaut refus. Le CDII prend effet au plus tard le mois qui suit. Après un refus, le compteur repart de zéro. (Avenant du 13 février 2006 relatif à l'animation commerciale, article 12.)</div>
    <div class="hint">Heures comptées d'après le planning des opérations d'animation (CIDD), absences et créneaux annulés exclus. Les briefings et formations payés hors planning ne sont pas comptés : ajoutez-les en créneaux si besoin. Alerte à partir de ${esc(String(H.suivi[0] ? H.suivi[0].alerte || 400 : 400))} h (Réglages).</div>
    ${urg.length ? `<div class="plan-test" style="background:#FBE3DF;border-color:#E9B4AA"><b>${urg.length} proposition${urg.length > 1 ? "s" : ""} de CDII à faire</b><br>${urg.map(x => esc(x.nom) + " : 500 h atteintes le " + esc(ctD(x.franchi_le)) + ", à proposer avant le " + esc(ctD(x.limite))).join("<br>")}</div>` : ""}
    ${H.modele_ok ? "" : `<div class="plan-test">Avant la première proposition : relisez et validez le texte de la lettre dans <b>Réglages › Proposition de CDI intermittent</b>.</div>`}
    <label class="ck"><input type="checkbox" id="cd-tous" ${CT.tous ? "checked" : ""}><span>Afficher aussi les personnes loin du seuil</span></label></div>
  ${CT.prop ? vCdiiProp() : ""}
  <div class="card">${L.length ? `<div class="tablewrap"><table><tr><th>Personne</th><th class="num">Heures sur 12 mois</th><th>Jauge</th><th>Situation</th><th>Échéances</th><th></th></tr>
    ${L.map(x => { const pc = Math.min(100, Math.round((x.heures_12m || 0) / 5)), P = x.proposition;
      return `<tr><td><b>${esc(x.nom)}</b><div class="hint">${esc(x.matricule || "")}${x.operations ? " · " + x.operations + " opération" + (x.operations > 1 ? "s" : "") : ""}</div></td>
      <td class="num">${x.heures_12m != null ? n(x.heures_12m) + " h" : ""}</td><td style="min-width:120px">${x.etat === "cdii" ? "" : `<div class="pp-bar"><i style="width:${pc}%"></i></div>`}</td>
      <td>${CDII_ETAT[x.etat] || ""}${x.dernier_refus ? `<div class="hint">refus le ${esc(ctD(x.dernier_refus.reponse_le || x.dernier_refus.reponse_avant))}, compteur depuis le ${esc(ctD(x.depuis))}</div>` : ""}</td>
      <td class="hint">${x.franchi_le && x.etat !== "prevu" ? `500 h le ${esc(ctD(x.franchi_le))}<br>proposer avant le <b>${esc(ctD(x.limite))}</b>${x.jours_restants >= 0 ? " (" + x.jours_restants + " j)" : ""}<br>prise d'effet au plus tard le ${esc(ctD(x.prise_effet_max))}` : ""}
        ${x.etat === "prevu" ? `500 h atteintes le ${esc(ctD(x.franchi_le))} d'après le planning` : ""}
        ${P && x.etat === "propose" ? `remise le ${esc(ctD(P.propose_le))} (${P.mode === "lrar" ? "recommandé" : "main propre"})<br>${P.presente_le ? "réponse attendue avant le <b>" + esc(ctD(P.reponse_avant)) + "</b>" : "date de 1re présentation à noter"}` : ""}
        ${P && x.etat === "cdii" ? `acceptée le ${esc(ctD(P.reponse_le))}, prise d'effet le ${esc(ctD(P.prise_effet))}` : ""}</td>
      <td>${["retard", "a_proposer", "prevu", "approche"].includes(x.etat) ? `<button class="${["retard", "a_proposer"].includes(x.etat) ? "primary" : "ghost"} sm" data-cdp="${x.animatrice_id}">${["retard", "a_proposer"].includes(x.etat) ? "Préparer la proposition" : "Proposer dès maintenant"}</button>` : ""}
        ${P ? `<button class="ghost sm" data-cdl="${P.id}">Lettre (PDF)</button>` : ""}
        ${P && x.etat === "propose" ? `<div class="btns" style="margin-top:6px">${P.presente_le ? "" : `<button class="ghost sm" data-cds="presente|${P.id}">1re présentation aujourd'hui</button>`}<button class="ghost sm" data-cds="accepte|${P.id}">Acceptée</button><button class="ghost sm" data-cds="refuse|${P.id}">Refusée</button><button class="ghost sm" data-cds="annuler|${P.id}">Annuler</button></div>` : ""}</td></tr>`; }).join("")}</table></div>`
    : `<div class="empty">Personne n'approche des 500 heures sur 12 mois.</div>`}</div>`;
}
function vCdiiProp(){
  const K = CT.calc, X = CT.prop; if (!K) return "";
  const c = K.calcul, n = v => String(v).replace(".", ",");
  return `<div class="card"><h2>Proposition de CDII · ${esc(X.nom)}</h2>
    <div class="muted">Période de référence : ${esc(ctD(K.ref.ref_debut))} au ${esc(ctD(K.ref.franchi_le))}, <b>${n(K.ref.heures)} h</b> travaillées. Les montants se recalculent quand vous changez une valeur.</div>
    <div class="row3"><label>Durée annuelle minimale (h)<input id="cd-vol" inputmode="numeric" value="${c.volume}"></label><label>Taux horaire brut (€)<input id="cd-taux" inputmode="decimal" value="${n(c.taux)}"></label>
      <label>Rémunération<select id="cd-rem"><option value="lissee" ${c.lisse ? "selected" : ""}>Lissée sur 12 mois</option><option value="reelle" ${c.lisse ? "" : "selected"}>Au fil des heures travaillées</option></select></label></div>
    <div class="row3"><label>Prise d'effet<input type="date" id="cd-pe" value="${c.prise_effet}" max="${c.prise_effet_max}"></label><label style="grid-column:span 2">Périodes de travail <span class="hint">facultatif, repris dans la lettre</span><input id="cd-per" value="${esc(c.periodes)}" placeholder="Ex. opérations de fêtes (novembre à décembre), fête des mères (mai), communiquées 15 jours avant"></label></div>
    <div class="tablewrap"><table class="ktab"><tr><td>Salaire annuel brut garanti (durée minimale)</td><td class="num"><b>${ctEur(c.annuel_brut)}</b></td></tr>
      ${c.lisse ? `<tr><td>Soit par mois, lissé</td><td class="num">${ctEur(c.mensuel_lisse)}</td></tr>` : ""}
      <tr><td>Heures au-delà de la durée minimale, sans accord de la personne</td><td class="num">jusqu'à ${n(c.au_dela_max)} h par an (un tiers)</td></tr>
      <tr><td>Coût brut pour ces heures en CIDD (salaire + fin de contrat 10 % + congés 10 %)</td><td class="num">${ctEur(c.cout_cidd)}</td></tr>
      <tr><td>Coût brut en CDII (salaire + congés 10 %, plus d'indemnité de fin de contrat)</td><td class="num">${ctEur(c.cout_cdii)}</td></tr>
      <tr><td><b>En CDII, pour le même volume</b></td><td class="num"><b>${ctEur(Math.abs(c.ecart))} ${c.ecart >= 0 ? "de moins" : "de plus"}</b> par an</td></tr></table></div>
    <div class="hint">En CDII, la durée minimale est due même sans mission : proposez un volume que vous êtes sûr(e) de fournir. Faites valider ces conditions au regard de votre convention et de vos accords.</div>
    <div class="row3"><label>Remise<select id="cd-mode"><option value="main">En main propre contre décharge</option><option value="lrar">Lettre recommandée avec AR</option></select></label><label>Datée du<input type="date" id="cd-le" value="${new Date().toISOString().slice(0, 10)}"></label><span></span></div>
    <div class="btns"><button class="primary" id="cd-ok" ${CT.H && CT.H.modele_ok ? "" : "disabled"}>Enregistrer la proposition et préparer la lettre</button><button class="ghost" id="cd-x">Fermer</button></div>
    ${CT.H && CT.H.modele_ok ? "" : `<div class="hint">Validez d'abord le texte de la lettre dans Réglages.</div>`}</div>`;
}
function bCdii(){
  const re = () => go("gestion");
  $("#cd-tous").onchange = e => { CT.tous = e.target.checked; re(); };
  const calc = async () => { const body = {animatrice_id:CT.prop.animatrice_id}; if ($("#cd-vol")) Object.assign(body, {volume:$("#cd-vol").value, taux:$("#cd-taux").value, remuneration:$("#cd-rem").value, prise_effet:$("#cd-pe").value, periodes:$("#cd-per").value});
    CT.calc = await papi("cdii_calcul", body); };
  document.querySelectorAll("[data-cdp]").forEach(b => b.onclick = async () => { CT.prop = CT.H.suivi.find(x => x.animatrice_id === +b.dataset.cdp); CT.calc = null; try { await calc(); re(); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-cdl]").forEach(b => b.onclick = () => window.open("planning_api.php?action=cdii_lettre&id=" + b.dataset.cdl, "_blank"));
  document.querySelectorAll("[data-cds]").forEach(b => b.onclick = async () => { const [quoi, id] = b.dataset.cds.split("|");
    if (["annuler", "refuse", "accepte"].includes(quoi) && b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer"; return; }
    try { await papi("cdii_suite", {id:+id, quoi}); toast({presente:"Date de présentation notée : 15 jours pour répondre", accepte:"Acceptée : préparez le contrat de travail intermittent", refuse:"Refus noté : le compteur repart de zéro", annuler:"Proposition annulée"}[quoi]); re(); } catch(e) { toast(e.message); } });
  if (!$("#cd-ok")) return;
  ["#cd-vol", "#cd-taux", "#cd-rem", "#cd-pe"].forEach(k => $(k).onchange = async () => { const keep = {mode:$("#cd-mode").value, le:$("#cd-le").value}; try { await calc(); re(); setTimeout(() => { $("#cd-mode").value = keep.mode; $("#cd-le").value = keep.le; }, 0); } catch(e) { toast(e.message); } });
  $("#cd-x").onclick = () => { CT.prop = null; CT.calc = null; re(); };
  $("#cd-ok").onclick = async () => { try { const r = await papi("cdii_proposer", {animatrice_id:CT.prop.animatrice_id, volume:$("#cd-vol").value, taux:$("#cd-taux").value, remuneration:$("#cd-rem").value, prise_effet:$("#cd-pe").value, periodes:$("#cd-per").value, mode:$("#cd-mode").value, propose_le:$("#cd-le").value});
    window.open("planning_api.php?action=cdii_lettre&id=" + r.id, "_blank"); CT.prop = null; CT.calc = null; toast("Proposition enregistrée : imprimez la lettre, faites signer la décharge ou envoyez-la en recommandé"); re(); } catch(e) { toast(e.message); } };
}
