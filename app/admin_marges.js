// Marges par opération et par marque : simulées au départ (charges patronales à un taux moyen), ajustées avec le réel du TESE chaque mois.
const MG = { du:"", au:"", R:null, par:"operation" };
const MG_PAR = [["operation", "Opération"], ["client", "Client"], ["marque", "Marque"], ["pdv", "Point d'intervention"], ["personne", "Intervenant(e)"], ["jour", "Jour"], ["mois", "Mois"]];
(() => { const d = new Date(); MG.au = d.toISOString().slice(0, 7); d.setMonth(d.getMonth() - 2); MG.du = d.toISOString().slice(0, 7); })();
async function loadMarges(){ MG.R = await papi("marges", null, "du=" + MG.du + "&au=" + MG.au); }
function vMarges(){
  const R = MG.R, T = R.total, e = v => gEur(v), pc = v => v == null ? "" : String(v).replace(".", ",") + " %";
  const ton = t => t == null ? "" : t < 10 ? "mg-bad" : t < 25 ? "mg-warn" : "mg-ok";
  const pill = r => ({reel:`<span class="pill p-ok">réel TESE</span>`, partiel:`<span class="pill p-warn">en partie réel</span>`, simule:`<span class="pill">simulé</span>`})[r] || "";
  const moisTxt = m => MOIS[+m.slice(5, 7) - 1] + " " + m.slice(0, 4);
  const parMois = {}; R.personnes.forEach(p => (parMois[p.mois] = parMois[p.mois] || []).push(p));
  const synth = {}; R.syntheses.forEach(s => synth[s.mois] = s);
  const nbSim = R.ops.filter(o => o.reel !== "reel").length;
  return `<div class="card accent"><h2>Marges</h2>
      <div class="muted">CA HT de chaque opération (facturé, sinon devis), moins les salaires bruts (préca et CP compris), les charges patronales, les frais (km, transport, repas) et les autres coûts. Tant que le réel du TESE n'est pas saisi pour un mois, les charges patronales sont simulées à ${pc(R.pct)} du brut.</div>
      <div class="row3"><label>Du mois<input type="month" id="mg-du" value="${MG.du}"></label><label>Au mois<input type="month" id="mg-au" value="${MG.au}"></label>
        <label>Charges patronales simulées (% du brut)<input id="mg-pct" inputmode="decimal" value="${String(R.pct).replace(".", ",")}"></label></div>
      <div class="btns"><button class="ghost" id="mg-pctok">Enregistrer le taux</button><a class="btn ghost" href="planning_api.php?action=marges_xlsx&du=${MG.du}&au=${MG.au}">Télécharger en Excel</a></div></div>
    <div class="card"><h2>Rapport d'analyse financière</h2>
      <div class="muted">Un PDF pour la direction sur la période choisie : chiffres clés comparés à la période précédente, structure des coûts, marges par marque, client, point d'intervention, intervenant(e) et mois, opérations les plus et les moins rentables, points d'attention.</div>
      <label>Note de la direction <span class="hint">facultatif, reprise en tête du rapport</span><textarea id="mgr-note" rows="2" placeholder="Ex. Hausse des frais de déplacement liée aux missions dans l'Est."></textarea></label>
      ${R.ia ? `<label class="ck"><input type="checkbox" id="mgr-ia" checked><span>Ajouter un commentaire rédigé automatiquement (à relire)</span></label>` : `<div class="hint">Le commentaire rédigé automatiquement n'est pas activé : il faut une clé API Claude dans config.php.</div>`}
      <div class="btns"><button class="primary" id="mgr-go">Créer le rapport PDF</button></div></div>
    <div class="mg-kpis"><div><span>CA HT</span><b>${e(T.ca)}</b></div><div><span>Coûts</span><b>${e(T.couts)}</b><em>salaires ${e(T.salaires)} · charges ${e(T.charges)} · frais ${e(T.frais)}${T.autres ? " · autres " + e(T.autres) : ""}</em></div>
      <div class="${ton(T.taux)}"><span>Marge</span><b>${e(T.marge)}</b></div><div class="${ton(T.taux)}"><span>Marge %</span><b>${pc(T.taux) || "–"}</b><em>${nbSim ? nbSim + " opération" + (nbSim > 1 ? "s" : "") + " encore simulée" + (nbSim > 1 ? "s" : "") : "tout est au réel"}</em></div></div>
    <div class="card"><h2>Par marque · ${esc(R.titre)}</h2>${R.marques.length ? `<div class="tablewrap"><table><tr><th>Marque</th><th class="num">Opérations</th><th class="num">CA HT</th><th class="num">Coûts</th><th class="num">Marge</th><th class="num">Marge %</th></tr>
      ${R.marques.map(m => `<tr><td><b>${esc(m.marque)}</b></td><td class="num">${m.ops}</td><td class="num">${e(m.ca)}</td><td class="num">${e(m.couts)}</td><td class="num"><b>${e(m.marge)}</b></td><td class="num"><span class="mg-t ${ton(m.taux)}">${pc(m.taux)}</span></td></tr>`).join("")}</table></div>` : `<div class="empty">Aucune opération sur cette période.</div>`}</div>
    <div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>Par ${esc((MG_PAR.find(x => x[0] === MG.par) || MG_PAR[0])[1].toLowerCase())}</h2>
      <div class="mg-par">${MG_PAR.map(([k, l]) => `<button data-mgpar="${k}" aria-current="${MG.par === k}">${l}</button>`).join("")}</div></div>
    ${MG.par !== "operation" ? mgGroupes(R) : R.ops.length ? `<div class="tablewrap"><table><tr><th>Opération</th><th class="num">CA HT</th><th class="num">Salaires</th><th class="num">Charges</th><th class="num">Frais</th><th class="num">Autres</th><th class="num">Marge</th><th class="num">%</th><th></th></tr>
      ${R.ops.map(o => `<tr><td><b>${esc(o.marque)}</b><div class="hint">${esc(o.objet)}${o.numero ? " · " + esc(o.numero) : ""}</div><div class="hint">${o.heures ? String(o.heures).replace(".", ",") + " h · " : ""}${esc(o.personnes.join(", "))}</div></td>
        <td class="num">${e(o.ca)}<div class="hint">${esc(o.ca_source)}${o.prorata < 1 ? " · " + Math.round(o.prorata * 100) + " % sur la période" : ""}</div></td><td class="num">${e(o.salaires)}</td><td class="num">${e(o.charges)}</td><td class="num">${e(o.frais)}</td>
        <td class="num">${e(o.autres)}${R.couts.filter(c => +c.devis_id === o.devis_id).map(c => `<div class="hint">${esc(c.libelle)} ${e(c.montant)} <a href="#" data-mgdel="${c.id}">✕</a></div>`).join("")}</td>
        <td class="num"><b>${e(o.marge)}</b></td><td class="num"><span class="mg-t ${ton(o.taux)}">${pc(o.taux)}</span></td><td>${o.permanent ? `<span class="pill">permanent</span>` : pill(o.reel)}</td></tr>`).join("")}</table></div>` : `<div class="empty">Aucune opération sur cette période.</div>`}
      ${R.ops.some(o => o.devis_id) ? `<h3 class="fh">Ajouter un coût à une opération</h3><div class="row3"><label>Opération<select id="mgc-op">${R.ops.filter(o => o.devis_id).map(o => `<option value="${o.devis_id}">${esc(o.marque + " · " + (o.numero || o.objet))}</option>`).join("")}</select></label>
        <label>Mois<select id="mgc-m">${R.mois.map(m => `<option value="${m}">${moisTxt(m)}</option>`).join("")}</select></label><label>Libellé<input id="mgc-l" placeholder="Ex. coach, tenues, échantillons"></label></div>
        <div class="row3"><label>Montant HT (€)<input id="mgc-v" inputmode="decimal"></label><label>&nbsp;<button class="ghost" id="mgc-ok">Ajouter</button></label><span></span></div>` : ""}</div>
    <div class="card"><h2>Réel du TESE</h2>
      <div class="muted">Chaque mois (+6 jours), déposez le <b>décompte de cotisations</b> du TESE (PDF « Avis de prélèvement »). L'appli lit les cotisations de chaque personne, en déduit les charges patronales (cotisations − part salariale à ${String(R.taux_sal).replace(".", ",")} % du brut) et passe la marge du mois en « réel TESE ». Un 1er clic montre ce qui sera repris, sans rien enregistrer.</div>
      <div class="row3"><label>Décompte de cotisations TESE (PDF)<input type="file" id="mgt-f" accept="application/pdf"></label><label>Part salariale moyenne (% du brut)<input id="mgt-sal" inputmode="decimal" value="${String(R.taux_sal).replace(".", ",")}"></label><label>&nbsp;<button class="primary" id="mgt-go">Lire le décompte</button></label></div>
      <div id="mgt-res"></div>
      ${mgNoms(R)}
      <div class="hint" style="margin-top:8px">Vous pouvez aussi corriger à la main ci-dessous (brut réel et charges patronales réelles), par exemple d'après un bulletin. Laissez vide pour garder la simulation.</div>
      ${R.mois.map(m => { const P = parMois[m] || []; const s = synth[m];
        return `<h3 class="fh">${moisTxt(m)}</h3>
        <div class="btns" style="margin:4px 0">${s ? `<a class="btn ghost sm" href="planning_api.php?action=marges_synthese_voir&mois=${m}" target="_blank" rel="noopener">Synthèse TESE : ${esc(s.nom)}</a>` : `<span class="hint">Synthèse TESE pas encore jointe.</span>`}
          <label class="btn ghost sm">${s ? "Remplacer" : "Joindre"} la synthèse<input type="file" accept="application/pdf,image/*" data-mgsy="${m}" hidden></label></div>
        ${P.length ? `<div class="tablewrap"><table><tr><th>Personne</th><th class="num">Brut simulé</th><th class="num">Charges simulées</th><th class="num">Cotisations TESE</th><th>Brut retenu</th><th>Charges patronales</th></tr>
          ${P.map(p => `<tr data-mgr="${m}|${p.animatrice_id}"><td>${esc(p.nom)}</td><td class="num">${e(p.brut_sim)}</td><td class="num">${e(p.charges_sim)}</td><td class="num">${p.cotis != null ? e(p.cotis) : ""}</td>
            <td><input data-k="brut" inputmode="decimal" value="${p.brut != null ? (+p.brut).toFixed(2).replace(".", ",") : ""}" style="max-width:130px"></td><td><input data-k="charges" inputmode="decimal" value="${p.charges != null ? (+p.charges).toFixed(2).replace(".", ",") : ""}" style="max-width:130px"></td></tr>`).join("")}</table></div>
          <div class="btns"><button class="primary" data-mgsave="${m}">Enregistrer le réel de ${moisTxt(m)}</button></div>` : `<div class="hint">Personne n'a travaillé ce mois-ci.</div>`}`; }).join("")}</div>`;
}
// Noms du décompte sans journée dans l'appli : à relier à une personne (prénom différent), ou à déclarer salarié permanent (marque ou structure).
function mgNoms(R){
  const e = v => gEur(v);
  const noms = [...new Map(R.hors.filter(h => !h.aid).map(h => [h.nom, h])).values()], S = R.salaries || [];
  const reco = R.hors.filter(h => h.aid), recoN = new Set(reco.map(h => h.nom)).size;
  const lab = {alias:"même personne que", permanent:"salarié permanent rattaché à", structure:"frais de structure"};
  const anims = (A.L.animatrices || []).filter(a => !/^effacée/.test(a.nom)).map(a => nm(a) + (a.matricule ? " · " + a.matricule : "")).sort();
  return `<datalist id="mg-anims">${anims.map(x => `<option value="${esc(x)}">`).join("")}</datalist><datalist id="mg-marques">${(R.liste_marques || []).map(x => `<option value="${esc(x)}">`).join("")}</datalist>
    ${recoN ? `<div class="hint" style="margin-top:8px">${recoN} personne${recoN > 1 ? "s" : ""} du décompte reconnue${recoN > 1 ? "s" : ""} mais sans journée dans l'appli ce mois-là (planning fait ailleurs, dans Plany par exemple) : ${e(reco.reduce((a, h) => a + +h.cotis, 0))} de cotisations non réparties. Rien à faire : dès que leurs missions sont dans le planning de l'appli, elles sont réparties.</div>` : ""}
    ${noms.length ? `<h3 class="fh">Noms du décompte non retrouvés dans la liste · ${e(R.hors.filter(h => !h.aid).reduce((a, h) => a + +h.cotis, 0))}</h3>
      <div class="hint">Pour chaque nom : si c'est une personne de la liste sous un autre prénom (prénom officiel / prénom d'usage), reliez-la. Si c'est un salarié permanent sans missions (CDI), indiquez son brut mensuel et rattachez-le à une marque ou aux frais de structure. C'est mémorisé pour les mois suivants.</div>
      <div class="tablewrap"><table><tr><th>Nom (décompte)</th><th class="num">Cotisations</th><th>C'est…</th><th></th></tr>
      ${noms.map((h, i) => `<tr data-mgn="${esc(h.nom)}"><td><b>${esc(h.nom)}</b></td><td class="num">${e(R.hors.filter(x => x.nom === h.nom).reduce((a, x) => a + +x.cotis, 0))}</td>
        <td><select data-k="type"><option value="">Laisser à part</option><option value="alias">Une personne de la liste</option><option value="permanent">Salarié permanent d'une marque</option><option value="structure">Salarié permanent, frais de structure</option></select>
          <div data-v="alias" hidden><input data-k="anim" list="mg-anims" placeholder="Tapez le nom…"></div>
          <div data-v="perm" hidden><input data-k="marque" list="mg-marques" placeholder="Marque"><input data-k="brut" inputmode="decimal" placeholder="Brut mensuel (€)"><input data-k="libelle" placeholder="Fonction, ex. Retail manager"></div></td>
        <td><button class="ghost sm" data-mgnok>Enregistrer</button></td></tr>`).join("")}</table></div>` : ""}
    ${S.length ? `<details class="mg-hors"><summary>Correspondances enregistrées (${S.length})</summary>${S.map(x => `<div class="gev"><b>${esc(x.nom_tese)}</b> : ${lab[x.type] || x.type} ${x.type === "alias" ? esc(x.personne || "") : x.type === "permanent" ? esc(x.marque) + " · " + gEur(x.brut) + " brut/mois" + (x.libelle ? " · " + esc(x.libelle) : "") : gEur(x.brut) + " brut/mois" + (x.libelle ? " · " + esc(x.libelle) : "")} <button class="ghost sm danger" data-mgnx="${esc(x.nom_tese)}">Retirer</button></div>`).join("")}</details>` : ""}`;
}
function mgGroupes(R){
  const L = (R.groupes || {})[MG.par] || [], e = v => gEur(v), pc = v => v == null ? "" : String(v).replace(".", ",") + " %";
  const ton = t => t == null ? "" : t < 10 ? "mg-bad" : t < 25 ? "mg-warn" : "mg-ok";
  const jr = k => /^\d{4}-\d{2}-\d{2}$/.test(k) ? gDay(k) + "/" + k.slice(0, 4) : /^\d{4}-\d{2}$/.test(k) ? MOIS[+k.slice(5, 7) - 1] + " " + k.slice(0, 4) : k;
  if (!L.length) return `<div class="empty">Aucune donnée sur cette période.</div>`;
  return `<div class="tablewrap"><table><tr><th>${esc((MG_PAR.find(x => x[0] === MG.par) || [])[1] || "")}</th><th class="num">Jours</th><th class="num">Heures</th><th class="num">CA HT</th><th class="num">Salaires</th><th class="num">Charges</th><th class="num">Frais</th><th class="num">Autres</th><th class="num">Marge</th><th class="num">%</th></tr>
    ${L.map(g => `<tr><td><b>${esc(jr(g.cle))}</b>${g.ops > 1 ? `<div class="hint">${g.ops} opérations</div>` : ""}</td><td class="num">${g.jours || ""}</td><td class="num">${g.heures ? String(g.heures).replace(".", ",") : ""}</td><td class="num">${e(g.ca)}</td><td class="num">${e(g.salaires)}</td><td class="num">${e(g.charges)}</td><td class="num">${e(g.frais)}</td><td class="num">${e(g.autres)}</td><td class="num"><b>${e(g.marge)}</b></td><td class="num"><span class="mg-t ${ton(g.taux)}">${pc(g.taux)}</span></td></tr>`).join("")}</table></div>
    <div class="hint">Le CA d'une opération est réparti à parts égales sur ses journées travaillées ; les frais (trajet, repas) sont comptés le jour où ils ont lieu.</div>`;
}
function bMarges(){
  $("#mgr-go").onclick = () => { const q = new URLSearchParams({action:"marges_rapport", du:MG.du, au:MG.au, note:$("#mgr-note").value.trim()}); if ($("#mgr-ia") && $("#mgr-ia").checked) q.set("ia", "1");
    toast("Rapport en préparation…"); window.open("planning_api.php?" + q.toString(), "_blank"); };
  document.querySelectorAll("[data-mgpar]").forEach(b => b.onclick = () => { MG.par = b.dataset.mgpar; $("#view").innerHTML = shell(vGestion()); bindShell(); bGestion(); });
  const anims = new Map((A.L.animatrices || []).map(a => [nm(a) + (a.matricule ? " · " + a.matricule : ""), a.id]));
  document.querySelectorAll("[data-mgn]").forEach(tr => { const t = tr.querySelector('[data-k="type"]');
    t.onchange = () => { tr.querySelector('[data-v="alias"]').hidden = t.value !== "alias"; tr.querySelector('[data-v="perm"]').hidden = !["permanent", "structure"].includes(t.value); tr.querySelector('[data-k="marque"]').hidden = t.value !== "permanent"; };
    tr.querySelector("[data-mgnok]").onclick = async ev => { const b = ev.target, body = {nom_tese:tr.dataset.mgn, type:t.value};
      if (!t.value) return toast("Choisissez ce que représente ce nom");
      if (t.value === "alias") { body.animatrice_id = anims.get(tr.querySelector('[data-k="anim"]').value.trim()); if (!body.animatrice_id) return toast("Choisissez la personne dans la liste proposée"); }
      else { body.brut = tr.querySelector('[data-k="brut"]').value; body.marque = tr.querySelector('[data-k="marque"]').value; body.libelle = tr.querySelector('[data-k="libelle"]').value; }
      b.disabled = true; try { const r = await papi("marges_salarie", body); toast("Enregistré" + (r.relus ? " · décompte" + (r.relus > 1 ? "s" : "") + " relu" + (r.relus > 1 ? "s" : "") : "")); go("gestion"); } catch(x) { toast(x.message); b.disabled = false; } }; });
  document.querySelectorAll("[data-mgnx]").forEach(b => b.onclick = async () => { try { await papi("marges_salarie", {nom_tese:b.dataset.mgnx, supprimer:1}); go("gestion"); } catch(x) { toast(x.message); } });
  const re = () => go("gestion");
  $("#mg-du").onchange = e => { if (e.target.value) { MG.du = e.target.value; if (MG.au < MG.du) MG.au = MG.du; re(); } };
  $("#mg-au").onchange = e => { if (e.target.value) { MG.au = e.target.value; if (MG.au < MG.du) MG.du = MG.au; re(); } };
  $("#mg-pctok").onclick = async () => { const v = $("#mg-pct").value.trim(); if (!/^\d+([.,]\d+)?$/.test(v)) return toast("Indiquez un pourcentage, par exemple 6,7");
    try { await gapi("reglages_save", {charges_pat:v}); G.ini = null; toast("Taux enregistré"); re(); } catch(e) { toast(e.message); } };
  if ($("#mgc-ok")) $("#mgc-ok").onclick = async () => { try { await papi("marges_cout", {devis_id:+$("#mgc-op").value, mois:$("#mgc-m").value, libelle:$("#mgc-l").value.trim(), montant:$("#mgc-v").value.trim()}); toast("Coût ajouté"); re(); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-mgdel]").forEach(a => a.onclick = async ev => { ev.preventDefault(); try { await papi("marges_cout", {supprimer:+a.dataset.mgdel}); re(); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-mgsave]").forEach(b => b.onclick = async () => { const m = b.dataset.mgsave, lignes = [];
    document.querySelectorAll(`[data-mgr^="${m}|"]`).forEach(tr => lignes.push({animatrice_id:+tr.dataset.mgr.split("|")[1], brut:tr.querySelector('[data-k="brut"]').value, charges:tr.querySelector('[data-k="charges"]').value}));
    const inc = lignes.find(l => (l.brut.trim() === "") !== (l.charges.trim() === "")); if (inc) return toast("Pour chaque personne, indiquez les deux montants (ou aucun).");
    try { const r = await papi("marges_reel", {mois:m, lignes}); toast(r.n + " personne" + (r.n > 1 ? "s" : "") + " au réel"); re(); } catch(e) { toast(e.message); } });
  const tb = $("#mgt-go"); if (tb) { $("#mgt-f").onchange = () => { tb.dataset.ok = ""; tb.textContent = "Lire le décompte"; $("#mgt-res").innerHTML = ""; };
    tb.onclick = async () => { const f = $("#mgt-f").files[0]; if (!f) return toast("Choisissez le PDF du décompte");
      const sal = $("#mgt-sal").value.trim(); if (sal && sal !== String(MG.R.taux_sal).replace(".", ",")) { try { await gapi("reglages_save", {cotis_sal:sal}); } catch(x) {} }
      const ok = tb.dataset.ok === "1", fd = new FormData(); fd.append("fichier", f); if (ok) fd.append("confirmer", "1"); tb.disabled = true;
      try { const r0 = await fetch("planning_api.php?action=marges_tese", {method:"POST", credentials:"same-origin", headers:{"X-Requested-With":"amaya"}, body:fd}); const r = await r0.json(); if (!r0.ok) throw new Error(r.error || "Erreur");
        const mt = m => MOIS[+m.slice(5, 7) - 1] + " " + m.slice(0, 4);
        $("#mgt-res").innerHTML = `<div class="plan-test"><b>${r.simule ? "Aperçu" : "Enregistré"} · ${r.mois.map(mt).join(", ")}</b> · ${r.ok.length} personne${r.ok.length > 1 ? "s" : ""} rattachée${r.ok.length > 1 ? "s" : ""}${r.controle ? ` · total contrôlé : ${gEur(r.total_lu)} = total du décompte` : ` · <b style="color:var(--terra)">attention, total lu ${gEur(r.total_lu)} ≠ décompte ${gEur(r.total_doc)}</b>`}
          ${r.ok.length ? `<div class="tablewrap"><table><tr><th>Personne</th><th class="num">Cotisations</th><th class="num">Brut (contrats)</th><th class="num">Charges patronales déduites</th></tr>${r.ok.map(o => `<tr><td>${esc(o.nom)}</td><td class="num">${gEur(o.cotis)}</td><td class="num">${gEur(o.brut)}</td><td class="num">${gEur(o.charges)}</td></tr>`).join("")}</table></div>` : ""}
          ${r.hors.length ? `<div style="margin-top:6px"><b>${r.hors.length} sans journée dans l'appli</b> (gardés à part)<div class="hint">${r.hors.map(h => esc(h.nom + " · " + gEur(h.cotis) + " · " + h.raison)).join("<br>")}</div></div>` : ""}
          ${r.regul.length ? `<div style="margin-top:6px"><b>Régularisations de mois antérieurs</b><div class="hint">${r.regul.map(x => esc(x.nom + " · " + mt(x.mois) + " · " + gEur(x.cotis) + (x.applique ? "" : " (personne non retrouvée)"))).join("<br>")}</div></div>` : ""}</div>`;
        if (r.simule) { tb.dataset.ok = "1"; tb.textContent = "Confirmer et mettre à jour les marges"; } else { toast("Cotisations TESE enregistrées"); MG.du = r.mois[0] < MG.du ? r.mois[0] : MG.du; const keep = $("#mgt-res").innerHTML; await go("gestion"); if ($("#mgt-res")) $("#mgt-res").innerHTML = keep; }
      } catch(e) { toast(e.message); } tb.disabled = false; };
  }
  document.querySelectorAll("[data-mgsy]").forEach(inp => inp.onchange = async () => { const f = inp.files[0]; if (!f) return; const fd = new FormData(); fd.append("mois", inp.dataset.mgsy); fd.append("fichier", f);
    try { const r = await fetch("planning_api.php?action=marges_synthese", {method:"POST", credentials:"same-origin", headers:{"X-Requested-With":"amaya"}, body:fd}); const d = await r.json(); if (!r.ok) throw new Error(d.error || "Erreur"); toast("Synthèse jointe"); re(); } catch(e) { toast(e.message); } });
}
