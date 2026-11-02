<?php require __DIR__ . '/lib.php'; header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#43515C">
<title><?= h(AG_COURT) ?> · Espace propriétaire</title>
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<style>
/* Keylia : l'espace du propriétaire. Ardoise, crème et or, sobre et rigoureux. */
@font-face{font-family:'Marcellus';font-style:normal;font-weight:400;font-display:swap;src:url('assets/fonts/marcellus-latin-400-normal.woff2') format('woff2')}
@font-face{font-family:'Jost';font-style:normal;font-weight:400;font-display:swap;src:url('assets/fonts/jost-latin-400-normal.woff2') format('woff2')}
@font-face{font-family:'Jost';font-style:normal;font-weight:500;font-display:swap;src:url('assets/fonts/jost-latin-500-normal.woff2') format('woff2')}
:root{--ardoise:#43515C;--ardoise-2:#55646F;--creme:#EAE6DA;--fond:#F4F1EA;--ink:#2C2F34;--soft:#626A70;--line:#E6E1D6;--or:#C6A15B;--or-clair:#F3E9D6;--ok:#3E8A68;--ok-clair:#E1EEE5;--bad:#8E3B2F;color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:var(--fond);color:var(--ink);font:16px/1.6 "Jost",system-ui,sans-serif}
.band{background:var(--ardoise);color:#fff;padding:22px 0 64px}
.in{max-width:1040px;margin:0 auto;padding:0 max(18px,env(safe-area-inset-left))}
.band .top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.brand{font-family:"Marcellus",Georgia,serif;letter-spacing:.22em;text-transform:uppercase;color:var(--creme)}
.label{font-size:.72rem;letter-spacing:.18em;text-transform:uppercase;color:#C9D0D5}
h1{font-family:"Marcellus",Georgia,serif;font-weight:400;font-size:2.3rem;line-height:1.15;margin:26px 0 4px;color:#fff}
.band p{margin:0;color:#D5DBDF}
nav.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-top:20px}
nav.tabs a{color:#fff;text-decoration:none;font-size:.88rem;padding:7px 13px;border:1px solid rgba(255,255,255,.3);border-radius:999px;white-space:nowrap}
nav.tabs a .n{background:var(--or);color:var(--ink);border-radius:999px;padding:0 7px;margin-left:6px;font-size:.75rem;font-weight:500}
main{margin-top:-44px;padding-bottom:70px}
.figs{background:#fff;border-radius:16px;padding:20px 22px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;box-shadow:0 16px 34px -22px rgba(44,47,52,.6)}
.figs b{display:block;font-family:"Marcellus",Georgia,serif;font-weight:400;font-size:2.1rem;line-height:1.1;color:var(--ardoise)}
.figs span{font-size:.85rem;color:var(--soft)}
section{background:#fff;border-radius:16px;padding:22px;margin-top:16px;scroll-margin-top:12px}
h2{font-family:"Marcellus",Georgia,serif;font-weight:400;font-size:1.45rem;margin:0 0 12px;color:var(--ardoise)}
.muted{color:var(--soft)}.small{font-size:.85rem}
table{width:100%;border-collapse:collapse;font-size:.95rem}
th,td{text-align:left;padding:10px 10px 10px 0;border-bottom:1px solid var(--line);vertical-align:top}
th{font-weight:500;font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:var(--soft)}
.tw{overflow-x:auto}
.ph{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.ph img{width:86px;height:86px;object-fit:cover;display:block;border-radius:10px}
.pill{display:inline-block;font-size:.78rem;padding:3px 10px;border-radius:999px;background:var(--or-clair);color:#6E5220;white-space:nowrap}
.pill.ok{background:var(--ok-clair);color:#245236}.pill.bad{background:#F6E1DC;color:var(--bad)}.pill.neutre{background:#EEF0F2;color:var(--ardoise)}
.btn{border:0;background:var(--ardoise);color:#fff;padding:10px 16px;border-radius:10px;font:inherit;font-size:.92rem;cursor:pointer;text-decoration:none;display:inline-block}
.btn.ghost{background:transparent;color:var(--ardoise);border:1px solid var(--ardoise)}.btn.or{background:var(--or);color:var(--ink)}.btn.lien{background:none;color:var(--ardoise);padding:4px 0;text-decoration:underline}
.item{border-top:1px solid var(--line);padding:14px 0}.item:first-of-type{border-top:0;padding-top:0}
.todo{border:1px solid var(--or);background:#FFFBF3;border-radius:14px;padding:16px;margin-top:10px}
.fil{display:grid;gap:10px;max-height:520px;overflow-y:auto;padding:2px}
.m{display:grid;gap:2px;max-width:82%}.m.nous{justify-self:start}.m.moi{justify-self:end;text-align:right}
.bul{padding:10px 13px;border-radius:14px;background:#EEF0F2;text-align:left}.m.moi .bul{background:var(--ardoise);color:#fff}
.m .ph img{width:70px;height:70px}
textarea,input[type=text]{width:100%;font:inherit;padding:11px 12px;border:1px solid var(--line);border-radius:10px;background:#fff}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}
.ov{position:fixed;inset:0;background:rgba(44,47,52,.55);z-index:300;display:flex;justify-content:center;align-items:flex-start;overflow-y:auto;padding:24px 12px}
.ov .box{background:#fff;border-radius:18px;max-width:860px;width:100%;padding:24px}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin:12px 0}
.grid2 b{display:block;font-family:"Marcellus",Georgia,serif;font-weight:400;font-size:1.7rem;color:var(--ardoise);line-height:1.1}
.piece{border-top:1px solid var(--line);padding:12px 0}
.demo-ruban{position:fixed;left:50%;transform:translateX(-50%);bottom:12px;z-index:150;display:flex;align-items:center;gap:4px;flex-wrap:wrap;justify-content:center;background:#2C2F34;color:#fff;font-size:12px;padding:5px 6px 5px 12px;max-width:calc(100% - 16px);border-radius:999px}
.demo-ruban .dr-t{margin-right:4px;opacity:.75}.demo-ruban a,.demo-ruban b{color:#fff;text-decoration:none;padding:6px 10px;white-space:nowrap;font-weight:500;border-radius:999px}.demo-ruban b{background:var(--ardoise)}
@media print{.band nav,.demo-ruban,.noprint{display:none!important}.ov{position:static;background:none;padding:0}.ov .box{max-width:none}}
</style>
</head>
<body>
<?= demo_actif() ? demo_ruban() : '' ?>
<div class="band"><div class="in"><div class="top"><span class="brand"><?= h(AG_COURT) ?></span><span class="label">Espace propriétaire</span></div><div id="hd"></div></div></div>
<main class="in" id="v"><section><p class="muted">Chargement…</p></section></main>
<script>
const tok = location.hash.slice(1);
const $ = s => document.querySelector(s);
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const fd = iso => new Date(String(iso).slice(0, 10) + "T12:00:00").toLocaleDateString("fr-FR", {day:"numeric", month:"long"});
const fdy = iso => new Date(String(iso).slice(0, 10) + "T12:00:00").toLocaleDateString("fr-FR", {day:"numeric", month:"long", year:"numeric"});
const dh = v => v == null ? "" : (+v).toLocaleString("fr-FR", {minimumFractionDigits:0, maximumFractionDigits:2}) + " DH";
const SRC = {airbnb:"Airbnb", booking:"Booking.com", abritel:"Abritel", direct:"Directe", ical:"Calendrier"};
const TYPES = {menage:"Ménage et contrôle", controle:"Contrôle", maintenance:"Maintenance", accueil:"Accueil", visite:"Visite"};
async function call(action, body){
  const isF = body instanceof FormData; if (isF) body.append("t", tok);
  const r = await fetch("conciergerie_api.php?action=" + action, {method:"POST", headers:isF ? {"X-Requested-With":"amaya"} : {"Content-Type":"application/json","X-Requested-With":"amaya"}, body:isF ? body : JSON.stringify(Object.assign({t:tok}, body || {}))});
  const d = await r.json().catch(() => ({})); if (!r.ok) throw new Error(d.error || "Erreur " + r.status); return d;
}
async function reduire(file){ // photos envoyées allégées (réseau mobile)
  try { const b = await createImageBitmap(file); const k = Math.min(1, 1600 / Math.max(b.width, b.height)); const c = document.createElement("canvas"); c.width = Math.round(b.width * k); c.height = Math.round(b.height * k);
    c.getContext("2d").drawImage(b, 0, 0, c.width, c.height); return await new Promise(ok => c.toBlob(ok, "image/jpeg", .84)); } catch(e) { return file; }
}
const photos = L => L.length ? `<div class="ph">${L.map(x => `<a href="${esc(x.url)}" target="_blank" rel="noopener"><img src="${esc(x.mini)}" alt="" loading="lazy"></a>`).join("")}</div>` : "";
function ouvrir(html){ const o = document.createElement("div"); o.className = "ov"; o.innerHTML = `<div class="box">${html}<div class="row noprint" style="margin-top:18px"><button class="btn ghost" data-x>Fermer</button><button class="btn ghost" data-pr>Imprimer</button></div></div>`;
  document.body.appendChild(o); o.querySelector("[data-x]").onclick = () => o.remove(); o.querySelector("[data-pr]").onclick = () => window.print(); o.onclick = e => { if (e.target === o) o.remove(); }; }

let D;
async function load(){
  try { D = await call("prop_vue"); } catch(e) { $("#hd").innerHTML = `<h1>Espace propriétaire</h1>`; $("#v").innerHTML = `<section><p>${esc(e.message)}</p></section>`; return; }
  const nomL = id => (D.logements.find(l => +l.id === +id) || {}).nom || "";
  const auj = new Date().toISOString().slice(0, 10), mois = auj.slice(0, 7);
  const finM = new Date(+mois.slice(0, 4), +mois.slice(5, 7), 1, 12).toISOString().slice(0, 10);
  const nuits = D.reservations.reduce((n, r) => { const a = r.arrivee > mois + "-01" ? r.arrivee : mois + "-01", d = r.depart < finM ? r.depart : finM; return n + Math.max(0, Math.round((new Date(d) - new Date(a)) / 864e5)); }, 0);
  const avenir = D.reservations.filter(r => r.arrivee >= auj);
  const devAttente = D.devis.filter(d => d.statut === "envoye"), devRegler = D.devis.filter(d => d.paiement === "a_regler"), depAttente = D.incidents.filter(i => i.proprio_statut === "demande");
  const aFaire = devAttente.length + depAttente.length + devRegler.length, rapNonLus = D.rapports.filter(r => !r.lu_at).length;
  $("#hd").innerHTML = `<h1>Bonjour ${esc(D.proprietaire)}</h1><p>Le suivi de ${D.logements.length > 1 ? "vos biens" : "votre bien"}, tenu à jour par ${esc(D.agence)}.</p>
    <nav class="tabs">${aFaire ? `<a href="#afaire">À traiter<span class="n">${aFaire}</span></a>` : ""}<a href="#messages">Messages${D.non_lus ? `<span class="n">${D.non_lus}</span>` : ""}</a><a href="#rapports">Rapports${rapNonLus ? `<span class="n">${rapNonLus}</span>` : ""}</a>
      <a href="#sejours">Séjours et états des lieux</a><a href="#devis">Devis</a><a href="#taxe">Taxe de séjour</a>${(D.notes || []).length ? `<a href="#avis">Avis des voyageurs</a>` : ""}</nav>`;
  const R = D.reglement || {};
  const reglerTxt = d => `<div class="small muted" style="margin-top:6px">À régler à ${esc(R.societe || D.agence)}${R.rib ? ` par virement : ${R.banque ? esc(R.banque) + ", " : ""}RIB <b>${esc(R.rib)}</b>` : ", selon les modalités convenues avec votre conciergerie"}. Référence : « ${esc(d.titre)} ».</div>`;
  const tx = D.taxe || [], q = (f, t) => `du ${fd(f)} au ${fdy(t)}`;
  $("#v").innerHTML = `<div class="figs"><div><b>${nuits}</b><span>nuits réservées ce mois</span></div><div><b>${avenir.length}</b><span>séjours à venir</span></div>
      <div><b>${D.missions.length}</b><span>interventions en 60 jours</span></div><div><b>${(D.notes || []).length ? (Math.round(D.notes.reduce((s, n) => s + (+n.proprete || 0), 0) / D.notes.length * 10) / 10).toLocaleString("fr-FR") + "/5" : "–"}</b><span>propreté notée par les voyageurs</span></div></div>
    ${aFaire ? `<section id="afaire"><h2>À traiter</h2>
      ${devAttente.map(d => `<div class="todo"><div class="row" style="justify-content:space-between;margin:0"><b>Devis avant travaux : ${esc(d.titre)}</b><b>${dh(d.montant)}</b></div>
        <div class="small muted">${esc(nomL(d.logement_id))}${d.prestataire ? " · " + esc(d.prestataire) : ""} · reçu le ${esc(fd(d.created_at))}</div>${d.description ? `<p style="margin:8px 0 0;white-space:pre-line">${esc(d.description)}</p>` : ""}${photos(d.photos)}
        <textarea id="dr${d.id}" rows="2" placeholder="Un commentaire pour la conciergerie (facultatif)" style="margin-top:10px"></textarea>
        <div class="row"><button class="btn" data-dok="${d.id}">J'accepte le devis</button><button class="btn ghost" data-dno="${d.id}">Je refuse</button></div></div>`).join("")}
      ${depAttente.map(i => `<div class="todo"><div class="row" style="justify-content:space-between;margin:0"><b>Dépense à valider : ${esc(i.description)}</b>${i.cout != null ? `<b>${dh(i.cout)}</b>` : ""}</div>
        <div class="small muted">${esc(nomL(i.logement_id))} · ${esc(fd(i.created_at))}</div>${i.photo_url ? `<div class="ph"><img src="${esc(i.photo_url)}" alt=""></div>` : ""}
        <div class="row"><button class="btn" data-iok="${i.id}">J'accepte</button><button class="btn ghost" data-ino="${i.id}">Je refuse</button></div></div>`).join("")}
      ${devRegler.map(d => `<div class="todo"><div class="row" style="justify-content:space-between;margin:0"><b>Règlement des travaux : ${esc(d.titre)}</b><b>${dh(d.montant)}</b></div><div class="small muted">Devis accepté le ${esc(fd(d.decide_at))}</div>${reglerTxt(d)}</div>`).join("")}</section>` : ""}
    <section id="messages"><h2>Messages</h2><div class="fil" id="fil">${D.messages.map(m => `<div class="m ${m.auteur === "proprietaire" ? "moi" : "nous"}"><div class="bul">${esc(m.texte).replace(/\n/g, "<br>")}${photos(m.photos)}</div>
        <div class="small muted">${m.auteur === "proprietaire" ? "Vous" : esc(m.nom || D.agence)} · ${esc(fd(m.created_at))} ${esc(String(m.created_at).slice(11, 16))}</div></div>`).join("") || `<p class="muted">Votre conciergerie vous écrira ici. Vous pouvez aussi lui écrire à tout moment.</p>`}</div>
      <textarea id="mt" rows="3" placeholder="Écrire à ${esc(D.agence)}…" style="margin-top:12px"></textarea>
      <div class="row"><label class="btn ghost" style="cursor:pointer">Joindre des photos<input type="file" id="mf" accept="image/*" multiple hidden></label><span class="small muted" id="mfn"></span><button class="btn" id="ms">Envoyer</button></div></section>
    <section id="rapports"><h2>Rapports</h2>${D.rapports.map(r => `<div class="item row" style="justify-content:space-between;margin:0"><span><b>${esc(r.titre)}</b><div class="small muted">${r.type === "visite" ? "Rapport de visite" : "Rapport de période"} · reçu le ${esc(fd(r.created_at))}</div></span>
        <span class="row" style="margin:0">${r.lu_at ? "" : `<span class="pill">Nouveau</span>`}<button class="btn ghost" data-rap="${r.id}">Lire</button></span></div>`).join("") || `<p class="muted">Les rapports envoyés par votre conciergerie apparaîtront ici.</p>`}</section>
    <section id="sejours"><h2>Séjours et états des lieux</h2><p class="small muted">Pour chaque séjour, l'état du logement pièce par pièce, en photos : à l'entrée (le ménage fait avant l'arrivée) et à la sortie (le ménage fait après le départ).</p>
      <div class="tw"><table><thead><tr><th>Logement</th><th>Séjour</th><th>Voyageurs</th><th>Canal</th><th>État des lieux</th></tr></thead><tbody>
      ${D.reservations.slice().reverse().map(r => `<tr><td>${esc(nomL(r.logement_id))}</td><td>${esc(fd(r.arrivee))} au ${esc(fd(r.depart))}</td><td>${+r.nb}</td><td>${esc(SRC[r.source] || r.source)}</td>
        <td>${r.edl_entree ? `<button class="btn lien" data-edl="${r.edl_entree}" data-sens="entrée">Entrée</button>` : `<span class="small muted">Entrée à venir</span>`} · ${r.edl_sortie ? `<button class="btn lien" data-edl="${r.edl_sortie}" data-sens="sortie">Sortie</button>` : `<span class="small muted">sortie à venir</span>`}</td></tr>`).join("") || `<tr><td colspan="5" class="muted">Aucun séjour sur la période.</td></tr>`}</tbody></table></div></section>
    <section id="devis"><h2>Devis et travaux</h2>${D.devis.map(d => `<div class="item"><div class="row" style="justify-content:space-between;margin:0"><b>${esc(d.titre)}</b><span class="row" style="margin:0"><b>${dh(d.montant)}</b>
        ${d.statut === "envoye" ? `<span class="pill">À valider</span>` : d.statut === "refuse" ? `<span class="pill bad">Refusé</span>` : d.paiement === "regle" ? `<span class="pill ok">Accepté · réglé</span>` : `<span class="pill neutre">Accepté · à régler</span>`}</span></div>
        <div class="small muted">${esc(nomL(d.logement_id))}${d.prestataire ? " · " + esc(d.prestataire) : ""} · reçu le ${esc(fd(d.created_at))}${d.decide_at ? " · votre réponse le " + esc(fd(d.decide_at)) : ""}${d.regle_at ? " · réglé le " + esc(fd(d.regle_at)) : ""}</div></div>`).join("") || `<p class="muted">Avant tous travaux, votre conciergerie vous soumet ici le devis : rien n'est engagé sans votre accord.</p>`}
      ${D.incidents.length ? `<h2 style="margin-top:22px;font-size:1.15rem">Signalements</h2><div class="tw"><table><thead><tr><th>Date</th><th>Logement</th><th>Description</th><th>Montant</th><th>État</th></tr></thead><tbody>
        ${D.incidents.map(i => `<tr><td>${esc(fd(i.created_at))}</td><td>${esc(nomL(i.logement_id))}</td><td>${esc(i.description)}</td><td>${i.cout != null ? dh(i.cout) : ""}</td><td>${i.statut === "ouvert" ? `<span class="pill">En cours</span>` : `<span class="pill ok">Réglé</span>`}</td></tr>`).join("")}</tbody></table></div>` : ""}</section>
    <section id="taxe"><h2>Taxe de séjour</h2><p class="small muted">La taxe de séjour est due à la commune pour chaque nuit et chaque personne, selon le tarif fixé par la commune. Elle se reverse chaque trimestre. Voici le décompte de vos logements.</p>
      <div class="tw"><table><thead><tr><th>Période</th><th>Nuitées taxables</th><th>Montant</th></tr></thead><tbody>${tx.map((t, i) => `<tr><td>${i ? "Trimestre en cours, " : "Trimestre précédent, "}${q(t.from, t.to)}</td><td>${t.nuitees}</td><td><b>${dh(t.montant)}</b>${t.sans_tarif ? ` <span class="small muted">(tarif communal à confirmer)</span>` : ""}</td></tr>`).join("")}</tbody></table></div></section>
    ${(D.notes || []).length ? `<section id="avis"><h2>Ce qu'en disent les voyageurs</h2><div class="tw"><table><thead><tr><th>Logement</th><th>Propreté</th><th>Logement</th><th>Accueil</th><th>Avis</th></tr></thead><tbody>
      ${D.notes.map(n => `<tr><td>${esc(nomL(n.logement_id))}</td><td>${n.proprete ?? "–"}/5</td><td>${n.logement ?? "–"}/5</td><td>${n.accueil ?? "–"}/5</td><td>${+n.n}</td></tr>`).join("")}</tbody></table></div>
      ${(D.commentaires || []).map(c => `<p style="margin:12px 0 0"><span class="small muted">${esc(fd(c.created_at))} · ${esc(nomL(c.logement_id))}</span><br>« ${esc(c.commentaire)} »</p>`).join("")}</section>` : ""}
    <p class="small muted" style="text-align:center;margin-top:22px">Espace personnel tenu par ${esc(D.agence)}. Ce lien vous est réservé : ne le transférez pas.</p>`;
  const fil = $("#fil"); if (fil) fil.scrollTop = fil.scrollHeight;
  $("#mf").onchange = () => $("#mfn").textContent = $("#mf").files.length ? $("#mf").files.length + " photo(s)" : "";
  $("#ms").onclick = async () => { const fdt = new FormData(); fdt.append("texte", $("#mt").value); for (const f of [...$("#mf").files].slice(0, 4)) fdt.append("photos[]", await reduire(f), "photo.jpg");
    try { await call("prop_ecrire", fdt); load(); } catch(e) { alert(e.message); } };
  const dec = async (action, id, decision, reponse) => { try { await call(action, {id, decision, reponse}); load(); } catch(e) { alert(e.message); } };
  document.querySelectorAll("[data-dok]").forEach(b => b.onclick = () => { if (confirm("Accepter ce devis ? La conciergerie pourra lancer les travaux.")) dec("prop_devis_decider", +b.dataset.dok, "ok", ($("#dr" + b.dataset.dok) || {}).value || ""); });
  document.querySelectorAll("[data-dno]").forEach(b => b.onclick = () => dec("prop_devis_decider", +b.dataset.dno, "refus", ($("#dr" + b.dataset.dno) || {}).value || ""));
  document.querySelectorAll("[data-iok]").forEach(b => b.onclick = () => dec("prop_valider", +b.dataset.iok, "ok"));
  document.querySelectorAll("[data-ino]").forEach(b => b.onclick = () => dec("prop_valider", +b.dataset.ino, "refus"));
  document.querySelectorAll("[data-edl]").forEach(b => b.onclick = async () => { try { const m = (await call("prop_mission", {id:+b.dataset.edl})).mission;
    ouvrir(`<span class="label" style="color:var(--soft)">État des lieux de ${esc(b.dataset.sens)}</span><h2 style="margin-top:4px">${esc(m.logement)}</h2><p class="muted">${esc(TYPES[m.type] || m.type)} du ${esc(fdy(m.date))}${m.fin ? ", terminé à " + esc(String(m.fin).slice(11, 16)) : ""}</p>
      ${m.pieces.map(p => `<div class="piece"><div class="row" style="justify-content:space-between;margin:0"><b>${esc(p.piece)}</b>${p.ok ? `<span class="pill ok">Contrôlée</span>` : `<span class="pill">Non contrôlée</span>`}</div>${photos(p.photos)}</div>`).join("")}`); } catch(e) { alert(e.message); } });
  document.querySelectorAll("[data-rap]").forEach(b => b.onclick = async () => { try { rapport((await call("prop_rapport", {id:+b.dataset.rap}))); } catch(e) { alert(e.message); } });
}
function rapport(X){
  const r = X.rapport, c = r.contenu || {};
  let corps = "";
  if (r.type === "visite") corps = `<p class="muted">${esc(TYPES[c.type] || c.type)} du ${esc(fdy(c.date))}${c.fin ? ", terminé à " + esc(String(c.fin).slice(11, 16)) : ""}</p>${(c.pieces || []).map(p => `<div class="piece"><div class="row" style="justify-content:space-between;margin:0"><b>${esc(p.piece)}</b>${p.ok ? `<span class="pill ok">Contrôlée</span>` : `<span class="pill">Non contrôlée</span>`}</div>${photos(p.photos)}</div>`).join("")}`;
  else corps = `<div class="grid2"><div><b>${c.total.nuits}</b><span class="small muted">nuits louées</span></div><div><b>${c.total.sejours}</b><span class="small muted">séjours</span></div><div><b>${c.total.menages_faits}</b><span class="small muted">ménages et contrôles</span></div><div><b>${dh(c.taxe.montant)}</b><span class="small muted">de taxe de séjour</span></div></div>
    <div class="tw"><table><thead><tr><th>Logement</th><th>Nuits</th><th>Occupation</th><th>Séjours</th><th>Propreté</th><th>Signalements</th><th>Dépenses</th></tr></thead><tbody>${c.logements.map(l => `<tr><td>${esc(l.nom)}</td><td>${l.nuits}</td><td>${l.occupation} %</td><td>${l.sejours}</td><td>${l.proprete != null ? String(l.proprete).replace(".", ",") + "/5" : "–"}</td><td>${l.incidents}</td><td>${dh(l.depenses)}</td></tr>`).join("")}</tbody></table></div>
    ${(c.avis || []).length ? `<h2 style="font-size:1.15rem;margin-top:18px">Les voyageurs</h2>${c.avis.map(a => `<p style="margin:8px 0">« ${esc(a.commentaire)} » <span class="small muted">${esc(a.logement)}, ${esc(fd(a.created_at))}</span></p>`).join("")}` : ""}
    ${(c.incidents || []).length ? `<h2 style="font-size:1.15rem;margin-top:18px">Signalements</h2>${c.incidents.map(i => `<p style="margin:6px 0">${esc(fd(i.created_at))} · ${esc(i.logement)} · ${esc(i.description)}${i.cout != null ? " · " + dh(i.cout) : ""}</p>`).join("")}` : ""}
    ${(c.photos || []).length ? `<h2 style="font-size:1.15rem;margin-top:18px">Dernières photos</h2>${photos(c.photos)}` : ""}`;
  ouvrir(`<span class="label" style="color:var(--soft)">${esc(X.agence)} · ${r.type === "visite" ? "Rapport de visite" : "Rapport"}</span><h2 style="margin-top:4px">${esc(r.titre)}</h2>
    <p class="small muted">Pour ${esc(X.proprietaire)} · envoyé le ${esc(fdy(r.created_at))}${r.par ? " par " + esc(r.par) : ""}</p>${r.note ? `<p style="white-space:pre-line;background:var(--fond);border-radius:12px;padding:12px 14px">${esc(r.note)}</p>` : ""}${corps}`);
}
load();
</script>
</body>
</html>
