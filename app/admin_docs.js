// Documents : règlements communs (charte, dress code, règlement intérieur, mutuelle), dépôt des bulletins et attestations du mois,
// et documents de chaque personne dans sa fiche. Réservé à la direction (vérifié côté serveur).
const DA = { docs:[], cats:{}, sal:{}, envois:[], mois:"", lot:null };
const DA_ADM = [["Pièce d'identité", ["cni", "passeport"]], ["IBAN / RIB", ["rib"]], ["Carte Vitale", ["vitale"]], ["Photo (badge)", ["photo"]], ["Titre de séjour", ["titre"]], ["Autres", ["autre"]]];
const DA_LAB = {cni:"Carte d'identité", passeport:"Passeport", titre:"Titre de séjour", vitale:"Carte Vitale", rib:"IBAN / RIB", photo:"Photo d'identité", autre:"Document", bulletin:"Bulletin de salaire", ft:"Attestation France Travail", sal_autre:"Document salarial"};
const daMois = p => p ? ["janvier","février","mars","avril","mai","juin","juillet","août","septembre","octobre","novembre","décembre"][+p.slice(5, 7) - 1] + " " + p.slice(0, 4) : "";
async function loadDocsAdm(){ const r = await api("docs_agence"); Object.assign(DA, r); if (!DA.lotMois) DA.lotMois = r.mois; }
function vDocsAdm(){
  const G = {}; DA.docs.forEach(d => (G[d.categorie] = G[d.categorie] || []).push(d));
  return `<div class="card accent"><h2>Documents</h2><div class="muted">Tous les documents sont chiffrés sur le serveur et ne s'ouvrent qu'à travers l'appli : une personne ne voit que les siens, la direction voit tout, et chaque consultation d'un document personnel par l'administration est enregistrée.</div></div>
    <div class="card"><h2>Bulletins de salaire et attestations du mois</h2>
      <div class="muted">Sélectionnez tous les fichiers d'un coup. Chacun est rattaché à la bonne personne d'après son nom de fichier : le matricule (ex. AG-0123) ou le NOM et le prénom. Un 1er clic montre la correspondance, sans rien déposer.</div>
      <div class="row3"><label>Type<select id="lot-type">${Object.entries(DA.sal).filter(([k]) => k !== "sal_autre").map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join("")}</select></label>
        <label>Mois<input type="month" id="lot-mois" value="${esc(DA.lotMois || "")}"></label><label>Fichiers (PDF)<input type="file" id="lot-f" multiple accept="application/pdf,image/*"></label></div>
      <div class="btns"><button class="primary" id="lot-go">Vérifier la correspondance</button></div><div id="lot-res"></div>
      ${DA.envois.length ? `<div class="tablewrap" style="margin-top:10px"><table><tr><th>Mois</th><th>Type</th><th class="num">Déposés</th><th class="num">Consultés</th></tr>${DA.envois.map(e => `<tr><td>${esc(daMois(e.periode) || "sans mois")}</td><td>${esc(DA.sal[e.type] || e.type)}</td><td class="num">${e.n}</td><td class="num">${e.vus}</td></tr>`).join("")}</table></div>` : ""}</div>
    <div class="card"><h2>Règlements et conditions</h2><div class="muted">Visibles par tous les intervenants dans « Mes documents », onglet Règlements.</div>
      ${Object.keys(DA.cats).filter(c => G[c]).map(c => `<h3 class="fh">${esc(DA.cats[c])}</h3>${G[c].map(d => `<div class="gev"><a href="piece.php?g=${d.id}" target="_blank" rel="noopener"><b>${esc(d.titre)}</b></a> <span class="hint">${esc(d.nom)} · déposé le ${esc(fmtD(d.created_at.slice(0, 10)))}</span> <button class="ghost sm danger" data-gdel="${d.id}">Retirer</button></div>`).join("")}`).join("") || `<div class="empty">Aucun document pour l'instant.</div>`}
      <div class="row3" style="margin-top:10px"><label>Catégorie<select id="g-cat">${Object.entries(DA.cats).map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join("")}</select></label>
        <label>Titre<input id="g-titre" placeholder="Ex. Charte ${AG} 2026"></label><label>Fichier (PDF ou photo)<input type="file" id="g-f" accept="application/pdf,image/*"></label></div>
      <div class="btns"><button class="primary" id="g-up">Ajouter</button></div></div>`;
}
function bDocsAdm(){
  $("#lot-mois").onchange = e => DA.lotMois = e.target.value;
  const lot = $("#lot-go"), reset = () => { lot.dataset.ok = ""; lot.textContent = "Vérifier la correspondance"; $("#lot-res").innerHTML = ""; };
  ["#lot-type", "#lot-mois", "#lot-f"].forEach(k => $(k).addEventListener("change", reset));
  lot.onclick = async () => { const F = $("#lot-f").files; if (!F.length) return toast("Choisissez les fichiers"); if (!$("#lot-mois").value) return toast("Indiquez le mois");
    const ok = lot.dataset.ok === "1", fd = new FormData(); fd.append("type", $("#lot-type").value); fd.append("periode", $("#lot-mois").value); [...F].forEach(f => fd.append("fichiers[]", f)); if (ok) fd.append("confirmer", "1");
    lot.disabled = true;
    try { const r = await api("docs_lot", fd);
      $("#lot-res").innerHTML = `<div class="plan-test"><b>${r.simule ? "Aperçu : " : ""}${r.reconnus.length} fichier${r.reconnus.length > 1 ? "s" : ""} ${r.simule ? "reconnu" + (r.reconnus.length > 1 ? "s" : "") : "déposé" + (r.reconnus.length > 1 ? "s" : "")}</b>${r.reconnus.length ? `<div class="hint">${r.reconnus.map(esc).join("<br>")}</div>` : ""}
        ${r.non_reconnus.length ? `<div style="margin-top:6px"><b style="color:var(--terra)">${r.non_reconnus.length} non rattaché${r.non_reconnus.length > 1 ? "s" : ""}</b> (à déposer depuis la fiche de la personne, ou renommez le fichier avec son matricule)<div class="hint">${r.non_reconnus.map(esc).join("<br>")}</div></div>` : ""}</div>`;
      if (r.simule && r.reconnus.length) { lot.dataset.ok = "1"; lot.textContent = "Confirmer le dépôt de " + r.reconnus.length + " fichier" + (r.reconnus.length > 1 ? "s" : ""); }
      else if (!r.simule) { toast("Documents déposés : ils apparaissent dans l'appli de chaque personne"); const keep = $("#lot-res").innerHTML; await loadDocsAdm(); go("documents"); setTimeout(() => { if ($("#lot-res")) $("#lot-res").innerHTML = keep; }, 50); }
    } catch(e) { toast(e.message); } lot.disabled = false; };
  $("#g-up").onclick = async () => { const f = $("#g-f").files[0]; if (!f) return toast("Choisissez le fichier"); const fd = new FormData(); fd.append("categorie", $("#g-cat").value); fd.append("titre", $("#g-titre").value.trim()); fd.append("fichier", f);
    try { DA.docs = (await api("docs_agence_upload", fd)).docs; toast("Document ajouté"); go("documents"); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-gdel]").forEach(b => b.onclick = async () => { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer"; return; }
    try { DA.docs = (await api("docs_agence_delete", {id:+b.dataset.gdel})).docs; go("documents"); } catch(e) { toast(e.message); } });
}
// Fiche d'une personne : documents administratifs (✓ / à fournir) et salariaux, dépôt au nom de la personne.
function ficheDocs(F){
  if (F.pieces === null) return `<h3 class="fh">Documents</h3><div class="hint">Visibles par la direction uniquement.</div>`;
  const P = F.pieces || [], lien = p => `<div class="fdoc"><a href="piece.php?id=${p.id}" target="_blank" rel="noopener">${esc(DA_LAB[p.type] || p.type)}${p.periode ? " · " + esc(daMois(p.periode)) : ""}</a> <span class="hint">${esc(p.titre || p.nom)}${p.par ? " · déposé par " + esc(p.par) : ""}${DA_LAB[p.type] && ["bulletin", "ft", "sal_autre"].includes(p.type) ? (p.vu_le ? " · consulté" : " · pas encore consulté") : ""}</span> <button class="ghost sm danger" data-fddel="${p.id}">Retirer</button></div>`;
  return `<h3 class="fh">Documents administratifs</h3>
    ${DA_ADM.map(([t, ty]) => { const L = P.filter(p => ty.includes(p.type)); return `<div class="fr"><span>${L.length ? "✓" : ty[0] === "titre" || ty[0] === "autre" ? "·" : "✕"} ${esc(t)}</span><div>${L.length ? L.map(lien).join("") : `<span class="muted">${ty[0] === "titre" || ty[0] === "autre" ? "aucun" : "à fournir"}</span>`}</div></div>`; }).join("")}
    <h3 class="fh">Documents salariaux</h3>
    ${P.filter(p => ["bulletin", "ft", "sal_autre"].includes(p.type)).map(lien).join("") || `<div class="muted">Aucun.</div>`}
    <div class="row3" style="margin-top:8px"><label>Déposer pour cette personne<select id="fd-type">${Object.entries(DA_LAB).map(([k, l]) => `<option value="${k}" ${k === "bulletin" ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></label>
      <label>Mois <span class="hint">bulletins, attestations</span><input type="month" id="fd-mois"></label><label>Fichier<input type="file" id="fd-f" accept="application/pdf,image/*"></label></div>
    <div class="btns"><button class="ghost" id="fd-up">Déposer</button></div>`;
}
function bFicheDocs(F, id){
  if (!$("#fd-up")) return;
  const maj = async () => { const box = document.querySelector("#fiche-modal .modal-box"), y = box ? box.scrollTop : 0; await showFiche(id); const nb = document.querySelector("#fiche-modal .modal-box"); if (nb) nb.scrollTop = y; };
  $("#fd-up").onclick = async () => { const f = $("#fd-f").files[0]; if (!f) return toast("Choisissez le fichier"); const fd = new FormData(); fd.append("animatrice_id", id); fd.append("type", $("#fd-type").value); fd.append("periode", $("#fd-mois").value); fd.append("fichier", f);
    try { await api("doc_admin_upload", fd); toast("Document déposé"); maj(); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-fddel]").forEach(b => b.onclick = async () => { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer"; return; }
    try { await api("doc_admin_delete", {id:+b.dataset.fddel}); toast("Document retiré"); maj(); } catch(e) { toast(e.message); } });
}
