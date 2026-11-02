<?php require __DIR__ . '/lib.php'; header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= h(AG_COURT) ?> · Espace propriétaire</title>
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<style>
:root{--ink:#111;--soft:#6a6864;--line:#e4e2de;--paper:#fff;--mist:#f4f3f1;--ok:#3f6b4e;--warn:#9a6a1a;color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:var(--mist);color:var(--ink);font:16px/1.6 "Helvetica Neue",Arial,system-ui,sans-serif}
.wrap{max-width:980px;margin:0 auto;padding:28px max(18px,env(safe-area-inset-left)) 60px}
header{display:flex;justify-content:space-between;align-items:baseline;gap:12px;border-bottom:1px solid var(--ink);padding-bottom:14px;margin-bottom:28px;flex-wrap:wrap}
.brand{font-family:Georgia,serif;letter-spacing:.3em}
h1{font-family:Georgia,serif;font-weight:400;font-size:2.2rem;line-height:1.1;margin:0 0 6px}
h2{font-family:Georgia,serif;font-weight:400;font-size:1.5rem;margin:0 0 14px}
.muted{color:var(--soft)}
.label{font-size:.7rem;letter-spacing:.24em;text-transform:uppercase;color:var(--soft)}
section{background:var(--paper);border:1px solid var(--line);padding:24px;margin-top:20px}
.figs{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-top:12px}
.figs b{display:block;font-family:Georgia,serif;font-weight:400;font-size:2.2rem;line-height:1}
table{width:100%;border-collapse:collapse;font-size:.95rem}
th,td{text-align:left;padding:10px 8px 10px 0;border-bottom:1px solid var(--line);vertical-align:top}
th{font-weight:400;font-size:.7rem;letter-spacing:.2em;text-transform:uppercase;color:var(--soft)}
.tw{overflow-x:auto}
.ph{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.ph img{width:84px;height:84px;object-fit:cover;display:block}
.tag{font-size:.66rem;letter-spacing:.18em;text-transform:uppercase;border:1px solid var(--ink);padding:3px 8px;white-space:nowrap}
.tag.wait{background:var(--ink);color:#fff}
.btn{border:1px solid var(--ink);background:var(--ink);color:#fff;padding:9px 14px;font:inherit;font-size:.85rem;cursor:pointer}
.btn.ghost{background:transparent;color:var(--ink)}
.mission{border-top:1px solid var(--line);padding:14px 0}
.mission:first-of-type{border-top:0}
.demo-ruban{position:fixed;left:50%;transform:translateX(-50%);bottom:12px;z-index:150;display:flex;align-items:center;gap:4px;flex-wrap:wrap;justify-content:center;background:#111;color:#fff;font-size:12px;padding:5px 6px 5px 12px;max-width:calc(100% - 16px)}
.demo-ruban .dr-t{margin-right:4px;opacity:.75}.demo-ruban a,.demo-ruban b{color:#fff;text-decoration:none;padding:6px 10px;white-space:nowrap;font-weight:500}.demo-ruban b{background:#444}
</style>
</head>
<body>
<?= demo_actif() ? demo_ruban() : '' ?>
<div class="wrap"><header><span class="brand" id="ag"><?= h(AG_COURT) ?></span><span class="label">Espace propriétaire</span></header><main id="v"><p class="muted">Chargement…</p></main></div>
<script>
const tok = location.hash.slice(1);
const $ = s => document.querySelector(s);
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const fd = iso => new Date(String(iso).slice(0, 10) + "T12:00:00").toLocaleDateString("fr-FR", {day:"numeric", month:"long"});
const eur = v => v == null ? "" : (+v).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2});
const SRC = {airbnb:"Airbnb", booking:"Booking.com", abritel:"Abritel", direct:"Directe", ical:"Calendrier"};
async function call(action, body){
  const r = await fetch("conciergerie_api.php?action=" + action, {method:"POST", headers:{"Content-Type":"application/json","X-Requested-With":"amaya"}, body:JSON.stringify(Object.assign({t:tok}, body || {}))});
  const d = await r.json().catch(() => ({})); if (!r.ok) throw new Error(d.error || "Erreur " + r.status); return d;
}
async function load(){
  let D; try { D = await call("prop_vue"); } catch(e) { $("#v").innerHTML = `<h1>Espace propriétaire</h1><section><p>${esc(e.message)}</p></section>`; return; }
  const nomL = id => (D.logements.find(l => +l.id === +id) || {}).nom || "";
  const auj = new Date().toISOString().slice(0, 10);
  const avenir = D.reservations.filter(r => r.depart >= auj), nuits = D.reservations.filter(r => r.arrivee.slice(0, 7) === auj.slice(0, 7)).reduce((n, r) => n + Math.round((new Date(r.depart) - new Date(r.arrivee)) / 864e5), 0);
  const aValider = D.incidents.filter(i => i.proprio_statut === "demande");
  $("#v").innerHTML = `<h1>Bonjour ${esc(D.proprietaire)}</h1><p class="muted">Le suivi de ${D.logements.length > 1 ? "vos biens" : "votre bien"}, tenu à jour par ${esc(D.agence)}.</p>
    <section><div class="figs"><div><b>${nuits}</b><span class="label">Nuits réservées ce mois</span></div><div><b>${avenir.length}</b><span class="label">Séjours à venir</span></div>
      <div><b>${D.missions.length}</b><span class="label">Interventions (60 jours)</span></div><div><b>${aValider.length}</b><span class="label">À valider</span></div></div></section>
    ${aValider.length ? `<section><h2>Dépenses à valider</h2>${aValider.map(i => `<div class="mission"><b>${esc(nomL(i.logement_id))}</b> · ${esc(fd(i.created_at))}<div>${esc(i.description)}</div>
      ${i.cout != null ? `<div>Montant : <b>${eur(i.cout)}</b></div>` : ""}${i.photo_url ? `<div class="ph"><img src="${esc(i.photo_url)}" alt="Photo du problème"></div>` : ""}
      <div style="display:flex;gap:8px;margin-top:10px"><button class="btn" data-ok="${i.id}">J'accepte</button><button class="btn ghost" data-no="${i.id}">Je refuse</button></div></div>`).join("")}</section>` : ""}
    ${(D.notes || []).length ? `<section><h2>Ce qu'en disent les voyageurs</h2><div class="tw"><table><thead><tr><th>Logement</th><th>Propreté</th><th>Logement</th><th>Accueil</th><th>Avis</th></tr></thead><tbody>
      ${D.notes.map(n => `<tr><td>${esc(nomL(n.logement_id))}</td><td>${n.proprete ?? "–"}/5</td><td>${n.logement ?? "–"}/5</td><td>${n.accueil ?? "–"}/5</td><td>${+n.n}</td></tr>`).join("")}</tbody></table></div>
      ${(D.commentaires || []).map(c => `<p style="margin:12px 0 0"><span class="muted">${esc(fd(c.created_at))} · ${esc(nomL(c.logement_id))}</span><br>« ${esc(c.commentaire)} »</p>`).join("")}<p class="muted" style="font-size:.85rem">Avis recueillis auprès des voyageurs à leur arrivée et après leur départ, sur les six derniers mois.</p></section>` : ""}
    <section><h2>Séjours</h2><div class="tw"><table><thead><tr><th>Logement</th><th>Arrivée</th><th>Départ</th><th>Voyageurs</th><th>Canal</th></tr></thead><tbody>
      ${D.reservations.map(r => `<tr><td>${esc(nomL(r.logement_id))}</td><td>${esc(fd(r.arrivee))}</td><td>${esc(fd(r.depart))}</td><td>${+r.nb}</td><td>${esc(SRC[r.source] || r.source)}</td></tr>`).join("") || `<tr><td colspan="5" class="muted">Aucun séjour sur la période.</td></tr>`}</tbody></table></div></section>
    <section><h2>Interventions</h2>${D.missions.map(m => `<div class="mission"><b>${esc(nomL(m.logement_id))}</b> · ${m.type === "menage" ? "Ménage et contrôle" : m.type === "maintenance" ? "Maintenance" : "Contrôle"} · ${esc(fd(m.date))}
      <div class="ph">${m.photos.map(u => `<a href="${esc(u.replace("&t=1", ""))}" target="_blank" rel="noopener"><img src="${esc(u)}" alt="Photo de l'intervention" loading="lazy"></a>`).join("")}</div></div>`).join("") || `<p class="muted">Aucune intervention sur les 60 derniers jours.</p>`}</section>
    <section><h2>Signalements</h2><div class="tw"><table><thead><tr><th>Date</th><th>Logement</th><th>Description</th><th>Montant</th><th>État</th></tr></thead><tbody>
      ${D.incidents.map(i => `<tr><td>${esc(fd(i.created_at))}</td><td>${esc(nomL(i.logement_id))}</td><td>${esc(i.description)}</td><td>${eur(i.cout)}</td><td><span class="tag ${i.statut === "ouvert" ? "wait" : ""}">${i.statut === "ouvert" ? "En cours" : "Réglé"}</span></td></tr>`).join("") || `<tr><td colspan="5" class="muted">Aucun signalement.</td></tr>`}</tbody></table></div></section>`;
  const dec = async (id, decision) => { try { await call("prop_valider", {id, decision}); load(); } catch(e) { alert(e.message); } };
  document.querySelectorAll("[data-ok]").forEach(b => b.onclick = () => dec(+b.dataset.ok, "ok"));
  document.querySelectorAll("[data-no]").forEach(b => b.onclick = () => dec(+b.dataset.no, "refus"));
}
load();
</script>
</body>
</html>
