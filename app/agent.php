<?php require __DIR__ . '/lib.php'; header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#43515C">
<title><?= h(AG_COURT) ?> · Mission</title>
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<style>
/* Page de mission ouverte depuis WhatsApp : sans compte, gros boutons, légère pour les petits téléphones et les petits forfaits. Couleurs Keylia : ardoise, crème, or. */
:root{--ink:#2C2F34;--soft:#626A70;--line:#E6E1D6;--paper:#fff;--mist:#F4F1EA;--ardoise:#43515C;--or:#C6A15B;--or-clair:#F3E9D6;--ok:#3E8A68;--bad:#8e3b2f;color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:var(--mist);color:var(--ink);font:17px/1.5 "Jost",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
[dir=rtl] body{font-family:"Noto Naskh Arabic","Geeza Pro",Tahoma,sans-serif}
.wrap{max-width:560px;margin:0 auto;padding:0 0 90px}
header{display:flex;justify-content:space-between;align-items:center;padding:16px 18px 54px;background:var(--ardoise);color:#EAE6DA;border-radius:0 0 22px 22px}
.brand{font-family:Georgia,serif;letter-spacing:.25em}
.lang{border:1px solid rgba(255,255,255,.45);color:#fff;border-radius:999px;background:none;padding:7px 13px;font:inherit;font-size:.85rem}
main{padding:0 12px}
.card{background:var(--paper);border:0;border-radius:15px;padding:16px;margin-top:10px;display:grid;gap:10px}
main>.card:first-child{margin-top:-40px;box-shadow:0 14px 30px -18px rgba(44,47,52,.55)}
h1{font-family:Georgia,serif;font-weight:400;font-size:1.6rem;margin:0;line-height:1.2;color:var(--ardoise)}
[dir=rtl] h1{font-family:inherit}
.muted{color:var(--soft);font-size:.95rem}
.big{display:block;width:100%;padding:18px;border:0;border-radius:13px;background:var(--ardoise);color:#fff;font:600 1.1rem system-ui,sans-serif;cursor:pointer}
.big.ok{background:var(--ok)}
.big:disabled{opacity:.5}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 15px;border:0;border-radius:11px;background:var(--ardoise);color:#fff;font:500 1rem system-ui,sans-serif;cursor:pointer;text-decoration:none}
.row .btn+.btn{background:var(--or);color:var(--ink)}
.row a.btn,.row a.btn+a.btn{background:var(--or-clair);color:#6E5220;padding:8px 13px;font-size:.9rem;border-radius:999px}
.row{display:flex;gap:8px;flex-wrap:wrap}
.piece{display:flex;justify-content:space-between;align-items:center;gap:8px}
.pill{font-size:.8rem;padding:4px 10px;border-radius:999px;background:var(--or-clair);color:#6E5220}
.pill.ok{background:#E1EEE5;color:#245236}
.ph{display:flex;gap:6px;flex-wrap:wrap}.ph img{width:70px;height:70px;object-fit:cover;border-radius:9px}
.note{white-space:pre-line;background:var(--or-clair);border-radius:10px;padding:10px 12px}
textarea{width:100%;font:inherit;padding:10px;border:1px solid var(--line);border-radius:10px}
.toast{position:fixed;left:50%;bottom:16px;transform:translateX(-50%);background:var(--ardoise);color:#fff;padding:12px 16px;max-width:92%;z-index:9;border-radius:10px}
.info{font-size:.82rem;color:var(--soft)}
[hidden]{display:none!important}
</style>
</head>
<body>
<div class="wrap"><header><span class="brand"><?= h(AG_COURT) ?></span><button class="lang" id="lang"></button></header><main id="v"><p class="muted">…</p></main></div>
<div class="toast" id="toast" hidden></div>
<script>
const T = {
 fr:{lang:"العربية", jarrive:"J'arrive", fini:"J'ai fini", photo:"Photo", valider:"Pièce faite", rouvrir:"Rouvrir", acces:"Accès", linge:"Linge", note:"À faire aussi", probleme:"Signaler un problème",
   decrire:"Décrivez le problème", envoyer:"Envoyer", annuler:"Annuler", merci:"Mission terminée. Merci !", reste:"Il reste :", envoi:"Envoi…", expire:"Ce lien n'est plus valable. Demandez-en un nouveau à votre responsable.",
   pos:"Votre position est relevée seulement quand vous appuyez sur « J'arrive » et « J'ai fini ».", signale:"Problème envoyé.", sansreseau:"Pas de réseau : réessayez dans un instant.", commence:"Mission commencée", maps:"Itinéraire", repere:"Repère", parking:"Stationnement", code:"Code", chemin:"Le chemin"},
 ar:{lang:"Français", jarrive:"وصلت", fini:"ساليت", photo:"تصويرة", valider:"البيت سالا", rouvrir:"عاود حل", acces:"الدخول", linge:"الفراش", note:"دير حتى هادي", probleme:"علّم على مشكل",
   decrire:"شرح المشكل", envoyer:"صيفط", annuler:"إلغاء", merci:"المهمة سالات. شكرا!", reste:"باقي:", envoi:"كيتصيفط…", expire:"هاد الرابط ما بقاش صالح. طلب رابط جديد من المسؤول.",
   pos:"البلاصة ديالك كاتتسجل غير ملي كاتضغط على «وصلت» و«ساليت».", signale:"المشكل تصيفط.", sansreseau:"ما كاينش الريزو، عاود من بعد شوية.", commence:"المهمة بدات", maps:"الطريق", repere:"علامة", parking:"الباركينغ", code:"الكود", chemin:"الطريق للدار"}
};
const tok = location.hash.slice(1); let L = (()=>{ try { return localStorage.getItem("orvia-agent-l") || "fr"; } catch(e) { return "fr"; } })(); let M = null;
const $ = s => document.querySelector(s), t = k => T[L][k];
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
function toast(m){ const e = $("#toast"); e.textContent = m; e.hidden = false; clearTimeout(toast.h); toast.h = setTimeout(() => e.hidden = true, 3500); }
async function call(action, body, isForm){
  let r; const o = body ? {method:"POST", headers:{"X-Requested-With":"amaya"}, body: isForm ? body : JSON.stringify(Object.assign({t:tok}, body))} : {};
  if (body && !isForm) o.headers["Content-Type"] = "application/json";
  try { r = await fetch("conciergerie_api.php?action=" + action + (body ? "" : "&t=" + tok), o); } catch(e) { throw new Error(t("sansreseau")); }
  const d = await r.json().catch(() => ({})); if (!r.ok) throw new Error(d.error || "Erreur " + r.status); return d;
}
const position = () => new Promise(ok => { if (!navigator.geolocation) return ok({}); navigator.geolocation.getCurrentPosition(p => ok({lat:p.coords.latitude, lng:p.coords.longitude}), () => ok({}), {enableHighAccuracy:true, timeout:10000, maximumAge:60000}); });
async function reduire(file){ try { const b = await createImageBitmap(file, {imageOrientation:"from-image"}); const r = Math.min(1, 1400 / Math.max(b.width, b.height));
  const c = document.createElement("canvas"); c.width = Math.round(b.width * r); c.height = Math.round(b.height * r); c.getContext("2d").drawImage(b, 0, 0, c.width, c.height);
  return await new Promise(res => c.toBlob(x => res(x || file), "image/jpeg", .8)); } catch(e) { return file; } }
async function charger(){ try { M = (await call("ma_mission")).mission; } catch(e) { M = null; $("#v").innerHTML = `<div class="card"><p>${esc(t("expire"))}</p></div>`; return; } vue(); }
function vue(){
  document.documentElement.dir = L === "ar" ? "rtl" : "ltr"; document.documentElement.lang = L; $("#lang").textContent = t("lang");
  if (!M) return; const fini = M.statut === "faite";
  $("#v").innerHTML = `<div class="card"><h1>${esc(M.logement)}</h1><div class="muted">${esc(M.adresse)} ${esc(M.ville)}</div><div class="muted">${esc(M.date.split("-").reverse().join("/"))}${M.heure ? " · " + esc(M.heure) : ""}</div>
      ${(() => { const V = M.livret || {}; return `${V.carte && V.carte.maps ? `<div class="row"><a class="btn" href="${esc(V.carte.maps)}" target="_blank" rel="noopener">📍 ${t("maps")}</a><a class="btn" href="${esc(V.carte.waze)}" target="_blank" rel="noopener">Waze</a></div>` : ""}
        ${V.repere ? `<div><b>${t("repere")} :</b> ${esc(V.repere)}</div>` : ""}${V.parking ? `<div><b>${t("parking")} :</b> ${esc(V.parking)}</div>` : ""}
        ${(V.etapes || []).length ? `<details><summary><b>${t("chemin")}</b></summary>${V.etapes.map((e, i) => `<div style="margin-top:8px">${e.photo ? `<img src="${esc(e.photo)}" alt="" loading="lazy" style="width:100%;max-height:260px;object-fit:cover;display:block">` : ""}<div>${i + 1}. ${esc(e.texte)}</div></div>`).join("")}</details>` : ""}
        ${V.code ? `<div><b>${t("code")} :</b> <span style="font-size:1.2rem;letter-spacing:.08em">${esc(V.code)}</span>${V.serrure ? ` <span class="muted">· ${esc(V.serrure)}</span>` : ""}</div>` : ""}`; })()}
      ${M.acces ? `<details><summary><b>${t("acces")}</b></summary><div style="white-space:pre-line">${esc(M.acces)}</div></details>` : ""}${M.kit_linge ? `<div><b>${t("linge")} :</b> ${esc(M.kit_linge)}</div>` : ""}
      ${M.note ? `<div class="note"><b>${t("note")}</b>\n${esc(M.note)}</div>` : ""}</div>
    ${fini ? `<div class="card"><b>${t("merci")}</b></div>` : M.commence ? `<div class="card"><span class="pill ok">${t("commence")}</span></div>` : `<div class="card"><button class="big" id="go">${t("jarrive")}</button><div class="info">${t("pos")}</div></div>`}
    ${M.pieces.map((p, i) => `<div class="card"><div class="piece"><b>${esc(p.piece)}</b><span class="pill ${p.ok ? "ok" : ""}">${p.ok ? "✓" : p.urls.length + "/" + p.photos}</span></div>
      ${p.consignes ? `<div class="muted">${esc(p.consignes)}</div>` : ""}<div class="ph">${p.urls.map(u => `<img src="${esc(u)}" alt="">`).join("")}</div>
      ${fini || !M.commence ? "" : `<div class="row"><label class="btn">📷 ${t("photo")}<input type="file" accept="image/*" capture="environment" data-ph="${i}" hidden></label><button class="btn" data-ok="${i}" data-v="${p.ok ? 0 : 1}">${p.ok ? t("rouvrir") : t("valider")}</button></div>`}</div>`).join("")}
    ${!fini && M.commence ? `<div class="card"><button class="big ok" id="fin">${t("fini")}</button></div>` : ""}
    ${fini ? "" : `<div class="card"><button class="btn" id="pb">${t("probleme")}</button><div id="pbf" hidden style="display:grid;gap:8px"><textarea id="pbd" rows="3" placeholder="${esc(t("decrire"))}"></textarea>
      <label class="btn">📷 ${t("photo")}<input type="file" accept="image/*" capture="environment" id="pbp" hidden></label><div class="row"><button class="btn" id="pbo">${t("envoyer")}</button><button class="btn" id="pbk">${t("annuler")}</button></div></div></div>`}`;
  if ($("#go")) $("#go").onclick = async e => { e.target.disabled = true; const p = await position(); try { await call("mission_debut", Object.assign({id:M.id}, p)); charger(); } catch(x) { toast(x.message); e.target.disabled = false; } };
  if ($("#fin")) $("#fin").onclick = async e => { e.target.disabled = true; const p = await position(); try { const r = await call("mission_terminer", Object.assign({id:M.id}, p)); if (r.ok === false) { toast(t("reste") + " " + r.manques.join(", ")); e.target.disabled = false; return; } charger(); } catch(x) { toast(x.message); e.target.disabled = false; } };
  document.querySelectorAll("[data-ph]").forEach(inp => inp.onchange = async () => { const f = inp.files[0]; if (!f) return; inp.parentElement.firstChild.textContent = t("envoi");
    const fd = new FormData(); fd.append("t", tok); fd.append("id", M.id); fd.append("piece", inp.dataset.ph); fd.append("photo", await reduire(f), "photo.jpg");
    try { await call("mission_photo", fd, true); } catch(x) { toast(x.message); } charger(); });
  document.querySelectorAll("[data-ok]").forEach(b => b.onclick = async () => { try { await call("mission_piece", {id:M.id, piece:+b.dataset.ok, ok:+b.dataset.v === 1}); charger(); } catch(x) { toast(x.message); } });
  if ($("#pb")) { $("#pb").onclick = () => { $("#pbf").hidden = false; $("#pb").hidden = true; }; $("#pbk").onclick = () => { $("#pbf").hidden = true; $("#pb").hidden = false; };
    $("#pbo").onclick = async () => { const fd = new FormData(); fd.append("t", tok); fd.append("mission", M.id); fd.append("description", $("#pbd").value); const f = $("#pbp").files[0]; if (f) fd.append("photo", await reduire(f), "photo.jpg");
      try { await call("incident_signaler", fd, true); toast(t("signale")); $("#pbf").hidden = true; $("#pb").hidden = false; $("#pbd").value = ""; } catch(x) { toast(x.message); } }; }
}
$("#lang").onclick = () => { L = L === "ar" ? "fr" : "ar"; try { localStorage.setItem("orvia-agent-l", L); } catch(e) {} vue(); };
charger();
</script>
</body>
</html>
