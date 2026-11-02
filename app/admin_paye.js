// Préparation de paye : chaque mois (du 1er au dernier jour), une ligne par contrat, au format du tableau « Salaires ».
const PY = { mois:new Date().toISOString().slice(0, 7), L:null, titre:"", pole:"", V:null };
async function loadPaye(){ const [r, v] = await Promise.all([papi("paye", null, "mois=" + PY.mois), papi("virements", null, "mois=" + PY.mois).catch(() => null)]); PY.L = r.lignes; PY.titre = r.titre; PY.V = v; }
const PY_POLES = {"":"Tous", anim:"Animation (CIDD)", hot:"Accueil (CDDU)"};
const pyMaj = x => [x.hs1 ? `HS ${String(x.hs1).replace(".", ",")} h +${x.t1} %` : "", x.hs2 ? `HS ${String(x.hs2).replace(".", ",")} h +${x.t2} %` : "", x.h_dimfer ? `dim./férié ${String(x.h_dimfer).replace(".", ",")} h` : "",
  x.h_nuit ? `nuit ${String(x.h_nuit).replace(".", ",")} h` : "", x.h_zti ? `<b style="color:var(--terra)">soirée ZTI ${String(x.h_zti).replace(".", ",")} h</b>` : ""].filter(Boolean).join("<br>");
function vPaye(){
  const tous = PY.L || [], deux = new Set(tous.map(x => x.pole || "anim")).size > 1, L = PY.pole ? tous.filter(x => (x.pole || "anim") === PY.pole) : tous, e = v => (+v || 0).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €";
  const n = v => String(Math.round((+v || 0) * 100) / 100).replace(".", ",");
  const s = k => L.reduce((a, x) => a + (+x[k] || 0), 0);
  const kmTxt = x => x.tc_jour ? "Transports en commun · " + e(x.tc_jour) + " / jour" : x.km.length ? x.km.map(k => n(k.km) + " km" + (x.km.length > 1 ? " × " + k.n + " j" : "")).join(" + ") : (x.km_inconnu ? "à compléter" : "");
  return `<div class="card accent"><h2>Préparation de paye</h2>
      <div class="muted">Du 1er au dernier jour du mois, une ligne par contrat : jours, heures et majorations (heures sup par semaine civile, dimanches, fériés, nuit, soirées en ZTI, selon vos règles de paye dans Réglages), taux horaire du contrat, préca et CP à 10 %, repas (un par journée de plus de 6 h, rien à 6 h ou moins), km aller-retour depuis l'adresse de départ. Les journées au planning sans contrat envoyé sont reprises et signalées. Charges, impôts et nets restent à saisir dans le fichier d'après les bulletins.</div>
      <div class="row3"><label>Mois<input type="month" id="py-m" value="${PY.mois}"></label>${deux || PY.pole ? `<label>Pôle<select id="py-pole">${Object.entries(PY_POLES).map(([k, l]) => `<option value="${k}" ${PY.pole === k ? "selected" : ""}>${l}</option>`).join("")}</select></label>` : ""}<div style="align-self:end"><button class="primary" id="py-dl" ${L.length ? "" : "disabled"}>Télécharger le fichier Excel</button></div></div>
      ${tous.some(x => x.pole === "hot") ? `<div class="hint">Contrats CDDU (accueil) : pas d'indemnité de fin de contrat (préca à 0), congés payés à 10 %.</div>` : ""}</div>
    <div class="card"><h2>${esc(PY.titre)} · ${L.length} ligne${L.length > 1 ? "s" : ""}</h2>
      ${L.length ? `<div class="tablewrap"><table class="ktab"><tr><th>Nom</th><th class="num">Jours</th><th>Période</th><th class="num">Heures</th><th>Majorations</th><th class="num">Taux</th><th class="num">Total brut</th><th class="num">Repas</th><th>Trajet par jour</th><th class="num">Km ou transport</th><th class="num">Frais</th><th>Remarques</th></tr>
        ${L.map(x => `<tr><td><b>${esc(x.nom)}</b>${x.pole === "hot" ? `<span class="tag-hot">CDDU</span>` : ""}<div class="hint">${esc(x.matricule)}</div></td><td class="num">${x.jours}</td><td>${esc(x.periode)}</td><td class="num">${n(x.heures)}</td><td class="hint" style="min-width:130px">${pyMaj(x)}</td>
          <td class="num">${e(x.taux)}</td><td class="num">${e(x.total_brut)}<div class="hint">brut ${e(x.brut)}</div></td><td class="num">${e(x.repas_eur)}<div class="hint">${x.repas_jours} repas</div></td>
          <td>${esc(kmTxt(x))}</td><td class="num">${e(x.tc_jour ? x.tc : x.km_eur)}</td><td class="num"><b>${e(x.frais)}</b></td><td class="hint" style="min-width:180px;${/pas |à |modifié/.test(x.remarques) ? "color:var(--terra)" : ""}">${esc(x.remarques)}</td></tr>`).join("")}
        <tr><td><b>Total</b></td><td class="num"><b>${s("jours")}</b></td><td></td><td class="num"><b>${n(s("heures"))}</b></td><td></td><td></td><td class="num"><b>${e(s("total_brut"))}</b></td><td class="num"><b>${e(s("repas_eur"))}</b></td><td></td><td class="num"><b>${e(s("km_eur") + s("tc"))}</b></td><td class="num"><b>${e(s("frais"))}</b></td><td></td></tr></table></div>`
      : `<div class="empty">Aucune journée ce mois-ci.</div>`}</div>${vVirements()}`;
}
/* ---------- Virements des salaires (SEPA) ---------- */
function vVirements(){
  const V = PY.V; if (!V || !V.lignes.length) return "";
  const e = v => (+v || 0).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €", ro = V.titulaire ? "" : "disabled";
  const prets = V.lignes.filter(x => x.net > 0 && x.iban_ok), tot = prets.reduce((a, x) => a + x.net, 0), exe = PY.exe || new Date(Date.now() + 864e5).toISOString().slice(0, 10);
  return `<div class="card"><h2>Virements des salaires</h2>
    <div class="muted">Saisissez le <b>net à payer</b> de chaque bulletin (ou collez-les depuis votre logiciel de paye), puis téléchargez le fichier SEPA à déposer dans l'espace de votre banque (virements groupés, format XML). L'estimation n'est qu'un repère : seul le net du bulletin fait foi.</div>
    ${V.banque.ok ? `<div class="hint">Compte débité : ${esc(V.banque.iban)} (Réglages > Banque et règlement)</div>` : `<div class="hint" style="color:var(--bad)">IBAN de l'agence absent ou invalide : Réglages > Banque et règlement.</div>`}
    <div class="tablewrap"><table class="ktab"><tr><th>Nom</th><th class="num">Total brut</th><th class="num">Frais</th><th class="num">Estimation</th><th class="num">Net à payer (bulletin)</th><th>IBAN</th></tr>
      ${V.lignes.map(x => `<tr><td><b>${esc(x.nom)}</b>${x.poles.includes("hot") ? `<span class="tag-hot">CDDU</span>` : ""}<div class="hint">${esc(x.matricule)}</div></td><td class="num">${e(x.brut)}</td><td class="num">${e(x.frais)}</td><td class="num hint">${e(x.estimation)}</td>
        <td class="num"><input data-vnet="${x.animatrice_id}" inputmode="decimal" value="${x.net === null ? "" : String(x.net).replace(".", ",")}" placeholder="à saisir" style="width:110px;text-align:right" ${ro}></td>
        <td>${x.iban_ok ? esc(x.iban) : `<span style="color:var(--bad)">${x.iban ? esc(x.iban) + " invalide" : "absent"}</span><div class="hint">à compléter dans la fiche</div>`}</td></tr>`).join("")}
      <tr><td colspan="4"><b>Prêts à virer : ${prets.length} / ${V.lignes.length}</b></td><td class="num"><b>${e(tot)}</b></td><td></td></tr></table></div>
    ${ro ? `<div class="hint">Saisie et fichier SEPA réservés à la direction.</div>` : `<div class="btns"><button class="ghost" id="vr-save">Enregistrer les nets</button></div>
    <details><summary>Coller les nets depuis le logiciel de paye</summary><div class="hint">Une ligne par personne : matricule ou NOM Prénom, puis le montant (séparés par un point-virgule ou une tabulation, comme un copier-coller Excel).</div>
      <textarea id="vr-txt" rows="5" placeholder="${esc((V.lignes[0] || {}).matricule || "AG-0001")};1234,56"></textarea><div class="btns"><button class="ghost" id="vr-coller">Importer</button></div></details>
    <div class="row3"><label>Date d'exécution<input type="date" id="vr-date" value="${exe}"></label><div style="align-self:end"><button class="primary" id="vr-sepa" ${prets.length && V.banque.ok ? "" : "disabled"}>Télécharger le fichier SEPA (${prets.length})</button></div></div>`}
    ${V.exports.length ? `<div class="hint">Déjà généré : ${V.exports.map(x => `${esc(x.created_at.slice(0, 16).replace("T", " "))} · ${x.nb} virement${x.nb > 1 ? "s" : ""} · ${e(x.total)} · par ${esc(x.par)}`).join(" ; ")}</div>` : ""}</div>`;
}
function bVirements(){
  if (!$("#vr-save")) return;
  const nets = () => { const o = {}; document.querySelectorAll("[data-vnet]").forEach(i => o[i.dataset.vnet] = i.value); return o; };
  $("#vr-save").onclick = async () => { try { const r = await papi("virements_save", {mois:PY.mois, nets:nets()}); PY.V.lignes = r.lignes; toast("Nets enregistrés"); redraw(); } catch(e) { toast(e.message); } };
  $("#vr-coller").onclick = async () => { try { const r = await papi("virements_coller", {mois:PY.mois, texte:$("#vr-txt").value}); PY.V.lignes = r.lignes;
    toast(r.enregistres + " net" + (r.enregistres > 1 ? "s" : "") + " importé" + (r.enregistres > 1 ? "s" : "") + (r.inconnus.length ? " · non reconnus : " + r.inconnus.join(", ") : "")); redraw(); } catch(e) { toast(e.message); } };
  $("#vr-date").onchange = e => PY.exe = e.target.value;
  $("#vr-sepa").onclick = async () => {
    try { await papi("virements_save", {mois:PY.mois, nets:nets()});
      const r = await fetch("planning_api.php?action=sepa&mois=" + PY.mois + "&date=" + $("#vr-date").value, {credentials:"same-origin"});
      if (!r.ok) { let m = "Erreur " + r.status; try { m = (await r.json()).error || m; } catch(x) {} throw new Error(m); }
      const ex = decodeURIComponent(r.headers.get("X-Sepa-Exclus") || ""), b = await r.blob(), a = document.createElement("a");
      a.href = URL.createObjectURL(b); a.download = "Virements_salaires_" + PY.mois + ".xml"; document.body.appendChild(a); a.click(); a.remove();
      toast(r.headers.get("X-Sepa-Nb") + " virement(s) dans le fichier" + (ex ? " · écartés : " + ex : "")); PY.V = await papi("virements", null, "mois=" + PY.mois); redraw();
    } catch(e) { toast(e.message); } };
}
function bPaye(){
  $("#py-m").onchange = e => { if (e.target.value) { PY.mois = e.target.value; go("gestion"); } };
  $("#py-dl").onclick = () => { window.location.href = "planning_api.php?action=paye_xlsx&mois=" + PY.mois + (PY.pole ? "&pole=" + PY.pole : ""); };
  if ($("#py-pole")) $("#py-pole").onchange = e => { PY.pole = e.target.value; redraw(); };
  bVirements();
}
