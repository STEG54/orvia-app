/* ---------- Reprise d'un historique de comptes rendus depuis Excel ---------- */
const MQ = { pv:null };
function mqCard(){
  if (!can("rapports")) return "";
  const pv = MQ.pv;
  if (!pv) return `<details class="card" id="mq-box"><summary><b>Reprendre un historique Excel</b> <span class="muted">· anciens reportings, pour l'historique et les statistiques</span></summary>
    <div class="muted" style="margin:8px 0">Choisissez un ou plusieurs fichiers de reporting (.xlsx). Chaque ligne avec un CA devient un compte rendu d'animation : date, intervenant(e), point d'intervention, marque, CA, objectif et commentaire.
    Un écran vous montre d'abord les correspondances, rien n'est enregistré avant votre validation. Les journées prévues sans CA ne sont pas reprises. Si vous rechargez un fichier mis à jour, les lignes déjà reprises sont mises à jour, sans doublon.</div>
    <label class="btn" style="display:inline-block">Choisir les fichiers Excel<input type="file" id="mq-files" accept=".xlsx" multiple hidden></label></details>`;
  const A = A_ANIMS(), M = A.L.magasins, mk = A.L.marques;
  const fmt = f => f.format === "mois" ? "un onglet par mois" : f.format === "annee" ? "une ligne par journée" : "colonnes non reconnues : il faut au moins une colonne DATE et une colonne CA";
  const eur = v => (+v).toLocaleString("fr-FR", {maximumFractionDigits:2}) + " €";
  const d = s => s ? s.split("-").reverse().join("/") : "";
  return `<div class="card accent" id="mq-box"><h2>Reprise des Excel de marque : vérifiez avant d'enregistrer</h2>
    <div class="tablewrap"><table><tr><th>Fichier</th><th>Lu</th><th>Marque</th><th>Comptes rendus</th><th>CA</th><th>Remarques</th></tr>
    ${pv.fichiers.map(f => `<tr><td><b>${esc(f.fichier)}</b><div class="hint">${fmt(f)}${f.colonnes.length ? " · " + esc(f.colonnes.join(", ")) : ""}</div></td><td>${d(f.du)} au ${d(f.au)}</td>
      <td><select data-mqm="${f.id}" style="min-width:190px"><option value="">Choisir…</option>${mk.map(m => `<option value="${m.id}" ${+f.marque_id === +m.id ? "selected" : ""}>${esc(m.nom)}</option>`).join("")}</select></td>
      <td>${f.lignes}</td><td>${eur(f.ca)}</td>
      <td class="hint">${f.prevues ? f.prevues + " journée(s) prévue(s) sans CA, non reprises. " : ""}${f.ignorees_detail.length ? "Journées sans CA (absence, annulation), non reprises : " + esc(f.ignorees_detail.join(" ; ")) + ". " : ""}${f.fixes.length ? "Dates corrigées : " + esc(f.fixes.join(" ; ")) + "." : ""}${f.doublons.length ? `<div style="color:var(--bad);font-weight:600">Date en double, à corriger dans l'Excel puis à recharger : ${esc(f.doublons.join(" ; "))}.</div>` : ""}</td></tr>`).join("")}</table></div>
    <label style="max-width:260px">Zone des points d'intervention à créer<select id="mq-zone">${A.L.zones.map(z => `<option ${z === "France" ? "selected" : ""}>${z}</option>`).join("")}</select></label>
    <h2>Intervenants</h2><div class="hint">« Nouvelle fiche » crée l'intervenant(e) sans téléphone : elle la retrouvera à son inscription si elle met le même prénom et le même nom. Deux orthographes du même prénom peuvent pointer vers la même personne.</div>
    <div class="tablewrap"><table><tr><th>Dans le fichier</th><th>Lignes</th><th>Intervenant(e) dans l'appli</th></tr>
    ${pv.personnes.map((p, i) => `<tr><td><b>${esc(p.label)}</b></td><td>${p.n}</td><td><div class="row">
      <select data-mqp="${i}"><option value="new" ${p.match ? "" : "selected"}>Nouvelle fiche…</option>${A.L.animatrices.map(a => `<option value="${a.id}" ${+p.match === +a.id ? "selected" : ""}>${esc(nm(a))}</option>`).join("")}</select>
      <span class="row" data-mqpn="${i}" ${p.match ? "hidden" : ""}><input placeholder="Prénom" data-k="prenom" value="${esc(p.prenom)}"><input placeholder="NOM" data-k="nom" value="${esc(p.nom)}"></span></div></td></tr>`).join("")}</table></div>
    <h2>Points d'intervention</h2>
    <div class="tablewrap"><table><tr><th>Dans le fichier</th><th>Lignes</th><th>Point d'intervention dans l'appli</th></tr>
    ${pv.magasins.map((m, i) => `<tr><td><b>${esc(m.label)}</b></td><td>${m.n}</td><td><div class="row">
      <select data-mqg="${i}"><option value="new" ${m.match ? "" : "selected"}>Nouveau point d'intervention…</option>${M.map(x => `<option value="${x.id}" ${+m.match === +x.id ? "selected" : ""}>${esc(x.zone + " · " + x.nom + (x.ville ? " (" + x.ville + ")" : ""))}</option>`).join("")}</select>
      <span class="row" data-mqgn="${i}" ${m.match ? "hidden" : ""}><input placeholder="Enseigne" data-k="enseigne" value="${esc(m.enseigne)}"><input placeholder="Nom" data-k="nom" value="${esc(m.nom)}"><input placeholder="Ville" data-k="ville" value="${esc(m.ville)}"></span></div></td></tr>`).join("")}</table></div>
    <div class="btns"><button class="primary" id="mq-go">Enregistrer ${pv.fichiers.reduce((a, f) => a + f.lignes, 0)} comptes rendus</button><button class="ghost" id="mq-x">Annuler</button></div>
    <div class="hint">Un compte rendu déjà saisi dans l'appli pour le(la) même intervenant(e), la même date et le même point d'intervention n'est jamais écrasé.</div></div>`;
}
const A_ANIMS = () => A;
function mqBind(){
  const inp = $("#mq-files");
  if (inp) inp.onchange = async () => { if (!inp.files.length) return; const fd = new FormData(); [...inp.files].forEach(f => fd.append("fichiers[]", f));
    toast("Lecture des fichiers…"); try { MQ.pv = await api("mq_preview", fd); go("rapports"); setTimeout(() => $("#mq-box")?.scrollIntoView({behavior:"smooth"}), 50); } catch(e) { toast(e.message); } inp.value = ""; };
  if (!MQ.pv) return;
  document.querySelectorAll("[data-mqp]").forEach(s => s.onchange = () => { document.querySelector(`[data-mqpn="${s.dataset.mqp}"]`).hidden = s.value !== "new"; });
  document.querySelectorAll("[data-mqg]").forEach(s => s.onchange = () => { document.querySelector(`[data-mqgn="${s.dataset.mqg}"]`).hidden = s.value !== "new"; });
  $("#mq-x").onclick = () => { MQ.pv = null; go("rapports"); };
  $("#mq-go").onclick = async () => {
    const pv = MQ.pv, map = {zone:$("#mq-zone").value, personnes:{}, magasins:{}, marques:{}};
    for (const s of document.querySelectorAll("[data-mqm]")) { if (!s.value) return toast("Choisissez la marque de chaque fichier."); map.marques[s.dataset.mqm] = +s.value; }
    for (const s of document.querySelectorAll("[data-mqp]")) { const p = pv.personnes[+s.dataset.mqp];
      if (s.value === "new") { const box = document.querySelector(`[data-mqpn="${s.dataset.mqp}"]`), o = {}; box.querySelectorAll("input").forEach(i => o[i.dataset.k] = i.value.trim());
        if (!o.prenom) return toast("Indiquez le prénom pour « " + p.label + " »."); map.personnes[p.label] = o; } else map.personnes[p.label] = +s.value; }
    for (const s of document.querySelectorAll("[data-mqg]")) { const m = pv.magasins[+s.dataset.mqg];
      if (s.value === "new") { const box = document.querySelector(`[data-mqgn="${s.dataset.mqg}"]`), o = {}; box.querySelectorAll("input").forEach(i => o[i.dataset.k] = i.value.trim());
        if (!o.nom) return toast("Indiquez le nom du point d'intervention « " + m.label + " »."); map.magasins[m.label] = o; } else map.magasins[m.label] = +s.value; }
    // Une même nouvelle personne écrite deux fois (Linsee, Linssee) : une seule fiche.
    const seen = {}; for (const [k, v] of Object.entries(map.personnes)) if (typeof v === "object") { const key = (v.prenom + "|" + (v.nom || "")).toLowerCase(); if (seen[key]) map.personnes[k] = seen[key]; else seen[key] = v; }
    const b = $("#mq-go"); b.disabled = true; b.textContent = "Enregistrement…";
    try { const r = await api("mq_import", {token:pv.token, map}); MQ.pv = null; await reloadLists();
      toast(`${r.crees} comptes rendus repris, ${r.maj} mis à jour${r.appli ? ", " + r.appli + " déjà saisis dans l'appli (non touchés)" : ""}${r.animatrices ? ", " + r.animatrices + " intervenant(e)(s) créée(s)" : ""}${r.magasins ? ", " + r.magasins + " point(s) de vente créé(s)" : ""}.`); go("rapports"); }
    catch(e) { toast(e.message); b.disabled = false; b.textContent = "Réessayer"; }
  };
}

/* ---------- Photothèque ---------- */
const PH = { f:{from:"", to:"", zone:"", ville:"", magasin:"", animatrice:"", marque:"", type:""}, d:null, sel:new Set() };
(() => { const n = new Date(), a = new Date(n.getFullYear(), n.getMonth() - 2, 1), iso = d => new Date(d.getTime() - d.getTimezoneOffset() * 6e4).toISOString().slice(0, 10); PH.f.from = iso(a); PH.f.to = iso(n); })();
async function loadPhotos(){ PH.d = await api("phototheque", null, new URLSearchParams(PH.f).toString()); }
const taille = o => o >= 1073741824 ? (o / 1073741824).toLocaleString("fr-FR", {maximumFractionDigits:2}) + " Go" : Math.round(o / 1048576).toLocaleString("fr-FR") + " Mo";
function vPhotos(){
  const d = PH.d, f = PH.f, o = d.options, st = d.stockage;
  const sel = (k, list, lab, all) => `<label>${lab}<select data-phf="${k}"><option value="">${all}</option>${list.map(x => { const v = typeof x === "object" ? x.id : x, t = typeof x === "object" ? x.nom : x;
    return `<option value="${esc(v)}" ${String(f[k]) === String(v) ? "selected" : ""}>${esc(t)}</option>`; }).join("")}</select></label>`;
  const byDay = {}; d.photos.forEach(p => (byDay[p.date] = byDay[p.date] || []).push(p));
  return `<div class="card"><div class="ph-store"><b>Espace utilisé par l'appli : ${taille(st.total)}</b>
      <span class="muted">${st.nb_photos.toLocaleString("fr-FR")} photos et fichiers : ${taille(st.fichiers)} · base de données : ${taille(st.base)}</span></div></div>
    <div class="card"><h2>Photothèque</h2>
    <div class="row3"><label>Du<input type="date" data-phf="from" value="${f.from}"></label><label>Au<input type="date" data-phf="to" value="${f.to}"></label>${sel("zone", o.zones, "Zone", "Toutes zones")}</div>
    <div class="row3">${sel("ville", o.villes, "Ville", "Toutes les villes")}${sel("magasin", o.magasins, "Point d'intervention", "Tous les points d'intervention")}${sel("animatrice", o.animatrices, "Intervenant(e)", "Toutes")}</div>
    <div class="row3">${sel("marque", o.marques, "Marque", "Toutes les marques")}${sel("type", [{id:"rayon", nom:"Photos de rayon"}, {id:"selfie", nom:"Selfies"}], "Type", "Tous")}
      <label>&nbsp;<button class="ghost" id="ph-reset">Effacer les filtres</button></label></div>
    <div class="ph-bar"><span><b>${d.total.toLocaleString("fr-FR")} photo${d.total > 1 ? "s" : ""}</b>${d.total > d.photos.length ? ` <span class="muted">(les ${d.photos.length} plus récentes affichées : réduisez la période)</span>` : ""}
      ${PH.sel.size ? ` · <b>${PH.sel.size} cochée${PH.sel.size > 1 ? "s" : ""}</b>` : ""}</span>
      <span class="btns" style="margin:0">${PH.sel.size ? `<button class="ghost" id="ph-none">Décocher</button><a class="btn" id="ph-zsel">Télécharger la sélection (ZIP)</a>${can("rapports") ? `<button class="ghost danger" id="ph-del">Supprimer la sélection</button>` : ""}` : `<button class="ghost" id="ph-allsel">Tout cocher</button>`}<a class="btn" id="ph-zall">Tout télécharger (ZIP)</a></span></div>
    <div class="hint">Touchez une photo pour l'agrandir, cochez le rond pour la sélectionner.</div></div>
    ${d.photos.length ? Object.keys(byDay).map(day => `<div class="card"><h2>${esc(new Date(day + "T12:00:00").toLocaleDateString("fr-FR", {weekday:"long", day:"numeric", month:"long", year:"numeric"}))} <span class="muted">· ${byDay[day].length}</span></h2>
      <div class="ph-grid">${byDay[day].map(p => `<figure class="ph-it ${PH.sel.has(+p.id) ? "on" : ""}" data-phid="${p.id}"><img src="${p.url}" alt="" loading="lazy"><button type="button" class="ph-ck" data-phck="${p.id}" aria-label="Cocher">✓</button>
        <figcaption><b>${esc(p.magasin)}</b><span>${esc(p.animatrice)} · ${esc(p.type === "Selfie" ? "selfie" : p.marque || "rayon")}</span><span>${esc(p.ville)}${p.zone ? " · " + esc(p.zone) : ""}</span></figcaption></figure>`).join("")}</div></div>`).join("")
      : `<div class="card"><div class="empty">Aucune photo pour ces filtres.</div></div>`}`;
}
function bPhotos(){
  const reload = async () => { try { await loadPhotos(); PH.sel = new Set([...PH.sel].filter(id => PH.d.photos.some(p => +p.id === id))); (PH.keep = true, go("photos")); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-phf]").forEach(el => el.onchange = () => { PH.f[el.dataset.phf] = el.value; reload(); });
  $("#ph-reset").onclick = () => { Object.assign(PH.f, {zone:"", ville:"", magasin:"", animatrice:"", marque:"", type:""}); reload(); };
  const q = extra => "api.php?action=phototheque_zip&" + new URLSearchParams(Object.assign({}, PH.f, extra || {})).toString();
  $("#ph-zall").href = q(); if ($("#ph-zsel")) $("#ph-zsel").href = q({ids:[...PH.sel].join(",")});
  if ($("#ph-allsel")) $("#ph-allsel").onclick = () => { PH.d.photos.forEach(p => PH.sel.add(+p.id)); (PH.keep = true, go("photos")); };
  if ($("#ph-del")) $("#ph-del").onclick = () => phDelete([...PH.sel]);
  if ($("#ph-none")) $("#ph-none").onclick = () => { PH.sel.clear(); (PH.keep = true, go("photos")); };
  document.querySelectorAll("[data-phck]").forEach(b => b.onclick = e => { e.stopPropagation(); const id = +b.dataset.phck; PH.sel.has(id) ? PH.sel.delete(id) : PH.sel.add(id); (PH.keep = true, go("photos")); });
  document.querySelectorAll("[data-phid]").forEach(el => el.onclick = () => phView(+el.dataset.phid));
}
/** Grande photo avec ses informations, flèches pour passer à la suivante. */
function phView(id){
  const L = PH.d.photos, i = L.findIndex(p => +p.id === id); if (i < 0) return; const p = L[i];
  document.getElementById("ph-lb")?.remove();
  const box = document.createElement("div"); box.id = "ph-lb";
  box.innerHTML = `<div class="ph-lb-in"><img src="${p.full}" alt=""><div class="ph-lb-info"><b>${esc(p.magasin)}</b> · ${esc(p.ville)}${p.zone ? " · " + esc(p.zone) : ""}<br>
    ${esc(new Date(p.date + "T12:00:00").toLocaleDateString("fr-FR"))} · ${esc(p.animatrice)} · ${esc(p.type === "Selfie" ? "selfie" : p.marque || "rayon")}
    <div class="btns" style="margin-top:8px"><button class="ghost" data-nav="-1" ${i ? "" : "disabled"}>‹ Précédente</button><a class="btn" href="${p.full}" download="${esc(p.nom)}">Télécharger</a>
    <button class="ghost" data-nav="1" ${i < L.length - 1 ? "" : "disabled"}>Suivante ›</button>${can("rapports") ? `<button class="ghost danger" data-phdel>Supprimer</button>` : ""}<button class="ghost" data-x>Fermer</button></div></div></div>`;
  box.querySelector("[data-phdel]")?.addEventListener("click", e => { e.stopPropagation(); box.remove(); document.removeEventListener("keydown", key); phDelete([+p.id]); });
  box.onclick = e => { if (e.target === box || e.target.closest("[data-x]")) { box.remove(); document.removeEventListener("keydown", key); } const n = e.target.closest("[data-nav]"); if (n && !n.disabled) { document.removeEventListener("keydown", key); phView(+L[i + +n.dataset.nav].id); } };
  const key = e => { if (e.key === "Escape") { box.remove(); document.removeEventListener("keydown", key); } if (e.key === "ArrowRight" && i < L.length - 1) { document.removeEventListener("keydown", key); phView(+L[i + 1].id); } if (e.key === "ArrowLeft" && i) { document.removeEventListener("keydown", key); phView(+L[i - 1].id); } };
  document.addEventListener("keydown", key);
  document.body.appendChild(box);
}

/** Suppression définitive, après confirmation. */
async function phDelete(ids){
  if (!ids.length) return;
  const ok = await askSheet(ids.length > 1 ? `Supprimer ${ids.length} photos ?` : "Supprimer cette photo ?",
    "La suppression est définitive : la photo disparaît aussi des comptes rendus et des rapports. Pensez à la télécharger avant si vous voulez la garder.", "Oui, supprimer", "Annuler");
  if (!ok) return;
  try { const r = await api("phototheque_delete", {ids}); ids.forEach(id => PH.sel.delete(id)); toast(r.n + " photo" + (r.n > 1 ? "s supprimées" : " supprimée")); await loadPhotos(); PH.keep = true; go("photos"); }
  catch(e) { toast(e.message); }
}

/* ---------- Espace marques : comptes clients ---------- */
const CC = { edit:null, prop:null, crees:null };
const CC_DROITS = [["ca","Chiffres d'affaires et objectifs"],["cr","Commentaires des comptes rendus"],["photos","Photos validées"],["telecharger","Téléchargements (Excel, PDF, photos)"],["pvp","Saisie des PVP du catalogue merchandising"]];
async function loadComptes(){ A.cc = (await api("clients_comptes")).comptes; }
const nomMarque = id => (A.L.marques.find(m => +m.id === +id) || {}).nom || "?";
const CIRC = {"":"", local:", marché local", tr:", travel retail"};
const baTxt = ba => (ba || []).map(k => "BA " + ((A.L.ba_modeles || {})[k]?.nom || k)).join(" · ");
const perTxt = (per, ba) => [(per || []).map(x => nomMarque(x.m) + " (" + (x.z && x.z.length ? x.z.join(", ") : "toutes zones") + (CIRC[x.c || ""] || "") + ")").join(" · "), baTxt(ba)].filter(Boolean).join(" · ");
/** Éditeur de périmètre : une ligne par marque, avec ses zones (aucune cochée = toutes). */
function perRows(per){
  const mk = A.L.marques.filter(m => +m.actif);
  const row = (x = {m:"", z:[], c:""}) => `<div class="per-row"><select data-pm>${`<option value="">Marque…</option>` + mk.map(m => `<option value="${m.id}" ${+x.m === +m.id ? "selected" : ""}>${esc(m.nom)}</option>`).join("")}</select>
    <div class="chips multi" data-pz>${A.L.zones.map(z => `<button type="button" class="chip" data-v="${z}" aria-pressed="${(x.z || []).includes(z)}">${z}</button>`).join("")}</div>
    <select data-pc style="width:auto">${[["","Tous circuits"],["local","Marché local"],["tr","Travel retail"]].map(([v, l]) => `<option value="${v}" ${(x.c || "") === v ? "selected" : ""}>${l}</option>`).join("")}</select><button type="button" class="ghost" data-prm>✕</button></div>`;
  return `<div class="per">${(per && per.length ? per : [{}]).map(row).join("")}</div><button type="button" class="ghost" data-padd>+ Ajouter une marque</button>
    <template data-ptpl>${row()}</template>`;
}
function readPer(box){ return [...box.querySelectorAll(".per-row")].map(r => ({m:+r.querySelector("[data-pm]").value, z:[...r.querySelectorAll('[data-pz] .chip[aria-pressed="true"]')].map(c => c.dataset.v), c:r.querySelector("[data-pc]").value})).filter(x => x.m); }
function vComptes(){
  const L = A.cc || [], url = location.href.replace(/admin\.php.*$/, "marques.php");
  const form = (c = {}) => { const dr = c.id ? String(c.droits || "").split(",") : CC_DROITS.map(d => d[0]);
    return `<div class="cc-form" data-ccid="${c.id || 0}">
      <div class="row3"><label>Nom<input data-k="nom" value="${esc(c.nom || "")}" placeholder="Ex. Claire Martin"></label><label>Société<input data-k="societe" value="${esc(c.societe || "")}" placeholder="Ex. Société cliente"></label>
        <label>E-mail (identifiant)<input data-k="email" type="email" value="${esc(c.email || "")}"></label></div>
      <div class="row3"><label>${c.id ? "Nouveau mot de passe <span class=\"hint\">· vide pour le garder</span>" : "Mot de passe <span class=\"hint\">· 12 caractères minimum</span>"}<input data-k="password" type="text" autocomplete="new-password"></label>
        <label>Noms des intervenants<select data-k="noms">${[["complet","Prénom et nom"],["prenom","Prénom seulement"],["non","Anonyme"]].map(([v, l]) => `<option value="${v}" ${(c.noms || "complet") === v ? "selected" : ""}>${l}</option>`).join("")}</select></label>
        ${c.id ? `<label class="ck" style="align-self:end"><input type="checkbox" data-k="actif" ${+c.actif ? "checked" : ""}><span>Compte actif</span></label>` : "<span></span>"}</div>
      <div class="muted" style="margin:6px 0 4px">Animations qu'il voit : une ligne par marque, avec ses zones <span class="hint">· aucune zone cochée = toutes les zones · travel retail = points d'intervention cochés « Travel retail »</span></div>${perRows(c.perimetre)}
      ${Object.keys(A.L.ba_modeles || {}).length ? `<div class="muted" style="margin:8px 0 4px">Reportings BA <span class="hint">· journées validées dans l'onglet Reportings BA</span></div>` : ""}<div class="pjs">${Object.entries(A.L.ba_modeles || {}).map(([k, m]) => `<label><input type="checkbox" data-cba="${k}" ${(c.ba || []).includes(k) ? "checked" : ""}> ${esc(m.nom)}</label>`).join("")}</div>
      <div class="muted" style="margin:8px 0 4px">Droits</div><div class="pjs">${CC_DROITS.map(([k, l]) => `<label><input type="checkbox" data-dr="${k}" ${dr.includes(k) ? "checked" : ""}> ${l}</label>`).join("")}</div>
      <div class="btns"><button class="primary" data-ccsave>${c.id ? "Enregistrer" : "Créer le compte"}</button>${c.id ? `<button class="ghost" data-cccancel>Annuler</button><button class="ghost danger" data-ccdel>Supprimer le compte</button>` : ""}</div></div>`; };
  const P = CC.prop;
  return `<div class="card accent"><h2>Espace marques</h2>
    <div class="muted">Chaque client se connecte sur <a href="${url}" target="_blank" rel="noopener"><b>${esc(url)}</b></a> avec son e-mail et son mot de passe.
    Il ne voit que les comptes rendus <b>validés</b> (onglet Animations), de ses marques dans ses zones, avec les photos montrées aux marques. Les selfies et les remarques sur le personnel ne sont jamais visibles.</div></div>
    ${CC.crees ? `<div class="card" style="border-color:#2F6B45"><h2>Comptes créés : notez les mots de passe, ils ne seront plus affichés</h2>
      ${CC.crees.length ? `<div class="tablewrap"><table><tr><th>Nom</th><th>E-mail</th><th>Mot de passe</th></tr>${CC.crees.map(c => `<tr><td>${esc(c.nom)}</td><td>${esc(c.email)}</td><td><code>${esc(c.password)}</code></td></tr>`).join("")}</table></div>
      <div class="hint">Vous pouvez aussi envoyer à chacun ses accès par e-mail depuis la liste ci-dessous (un nouveau mot de passe est alors créé).</div>` : `<div class="muted">Aucun compte créé (déjà existants ?).</div>`}
      <button class="ghost" id="cc-okc">J'ai noté</button></div>` : ""}
    <div class="card"><h2>Créer les comptes depuis les listes de destinataires</h2>
      <div class="muted">Chaque adresse de vos listes (onglet Envois) devient un compte, avec exactement les marques et les zones de ses listes : par exemple une personne présente sur deux listes d'une même marque, dans deux zones, verra cette marque dans ces deux zones. Les adresses internes à ${AG} ne sont pas cochées.</div>
      ${P ? (P.length ? `<div class="tablewrap"><table><tr><th></th><th>E-mail</th><th>Nom</th><th>Ce qu'il verra</th><th>Listes</th></tr>
        ${P.map((p, i) => `<tr><td><input type="checkbox" data-pi="${i}" ${p.interne || p.existe ? "" : "checked"} ${p.existe ? "disabled" : ""}></td><td>${esc(p.email)}${p.existe ? ` <span class="pill ok">déjà un compte</span>` : p.coach ? ` <span class="pill">coach, son île seulement</span>` : p.interne ? ` <span class="pill warn">interne ${AG}</span>` : ""}</td>
          <td><input data-pn="${i}" style="min-width:150px" value="${esc(p.nom)}"></td><td>${esc(perTxt(p.perimetre, p.ba))}</td><td class="hint">${esc(p.listes.join(", "))}</td></tr>`).join("")}</table></div>
        <div class="btns"><button class="primary" id="cc-lot">Créer les comptes cochés</button><button class="ghost" id="cc-px">Fermer</button></div>` : `<div class="empty">Aucune adresse dans les listes reliées à des marques.</div>`)
        : `<button class="ghost" id="cc-prop">Voir les comptes proposés</button>`}</div>
    <div class="card"><h2>Nouveau compte</h2>${form()}</div>
    <div class="card"><h2>Comptes (${L.length})</h2>${L.length ? `<div class="tablewrap"><table><tr><th>Nom</th><th>E-mail</th><th>Ce qu'il voit</th><th>Dernière connexion</th><th></th></tr>
      ${L.map(c => CC.edit === +c.id ? `<tr><td colspan="5">${form(c)}</td></tr>` : `<tr><td><b>${esc(c.nom)}</b>${+c.actif ? "" : ` <span class="pill warn">désactivé</span>`}<div class="hint">${esc(c.societe)}</div></td><td>${esc(c.email)}</td><td>${esc(perTxt(c.perimetre, c.ba))}</td>
        <td class="muted">${c.derniere_connexion ? esc(c.derniere_connexion.slice(0, 16).replace(" ", " à ")) : "jamais"}</td><td style="white-space:nowrap"><button class="ghost" data-ccview="${c.id}">Voir comme lui</button> <button class="ghost" data-ccmail="${c.id}">Envoyer ses accès</button> <button class="ghost" data-ccedit="${c.id}">Modifier</button></td></tr>`).join("")}</table></div>`
      : `<div class="empty">Aucun compte pour l'instant.</div>`}</div>`;
}
function bComptes(){
  const per = root => { root.querySelectorAll("[data-pz]").forEach(box => box.onclick = e => { const b = e.target.closest(".chip"); if (b) b.setAttribute("aria-pressed", b.getAttribute("aria-pressed") !== "true"); });
    root.querySelectorAll("[data-prm]").forEach(b => b.onclick = () => { const r = b.closest(".per-row"); if (r.parentNode.children.length > 1) r.remove(); }); };
  document.querySelectorAll(".cc-form").forEach(f => { per(f); f.querySelector("[data-padd]").onclick = () => { f.querySelector(".per").insertAdjacentHTML("beforeend", f.querySelector("[data-ptpl]").innerHTML); per(f); }; });
  document.querySelectorAll("[data-ccedit]").forEach(b => b.onclick = () => { CC.edit = +b.dataset.ccedit; go("clients"); });
  document.querySelectorAll("[data-cccancel]").forEach(b => b.onclick = () => { CC.edit = null; go("clients"); });
  document.querySelectorAll("[data-ccsave]").forEach(b => b.onclick = async () => {
    const f = b.closest(".cc-form"), body = {id:+f.dataset.ccid};
    f.querySelectorAll("[data-k]").forEach(i => body[i.dataset.k] = i.type === "checkbox" ? (i.checked ? 1 : 0) : i.value.trim());
    body.perimetre = readPer(f); body.droits = [...f.querySelectorAll("[data-dr]:checked")].map(c => c.dataset.dr);
    body.ba = [...f.querySelectorAll("[data-cba]:checked")].map(c => c.dataset.cba);
    if (!body.perimetre.length && !body.ba.length) return toast("Choisissez au moins une marque.");
    try { await api("client_compte_save", body); toast(body.id ? "Compte modifié" : "Compte créé"); CC.edit = null; go("clients"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-ccdel]").forEach(b => b.onclick = async () => { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer la suppression"; return; }
    try { await api("client_compte_delete", {id:+b.closest(".cc-form").dataset.ccid}); CC.edit = null; toast("Compte supprimé"); go("clients"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-ccview]").forEach(b => b.onclick = async () => { const w = window.open("about:blank", "_blank");
    try { await api("client_apercu", {id:+b.dataset.ccview}); w.location.href = "marques.php"; } catch(e) { w && w.close(); toast(e.message); } });
  document.querySelectorAll("[data-ccmail]").forEach(b => b.onclick = async () => { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer l'envoi"; return; }
    b.disabled = true; try { await api("client_envoyer_acces", {id:+b.dataset.ccmail}); toast("Accès envoyés par e-mail (nouveau mot de passe)"); b.textContent = "Envoyé"; } catch(e) { toast(e.message); b.disabled = false; } });
  if ($("#cc-prop")) $("#cc-prop").onclick = async () => { try { CC.prop = (await api("clients_depuis_listes")).propositions; go("clients"); } catch(e) { toast(e.message); } };
  if ($("#cc-px")) $("#cc-px").onclick = () => { CC.prop = null; go("clients"); };
  if ($("#cc-okc")) $("#cc-okc").onclick = () => { CC.crees = null; go("clients"); };
  if ($("#cc-lot")) $("#cc-lot").onclick = async () => {
    const comptes = [...document.querySelectorAll("[data-pi]:checked")].map(c => { const p = CC.prop[+c.dataset.pi]; return {email:p.email, nom:$(`[data-pn="${c.dataset.pi}"]`).value.trim() || p.nom, societe:p.societe, perimetre:p.perimetre, ba:p.ba}; });
    if (!comptes.length) return toast("Cochez au moins une adresse.");
    try { CC.crees = (await api("clients_creer_lot", {comptes})).crees; CC.prop = null; go("clients"); } catch(e) { toast(e.message); } };
}
