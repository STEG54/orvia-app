<?php require __DIR__ . '/lib.php'; header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= h(AG_COURT) ?> · Votre arrivée</title>
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<style>
:root{--ink:#111;--soft:#6a6864;--line:#e4e2de;--paper:#fff;--mist:#f4f3f1;--ok:#3f6b4e;--bad:#8e3b2f;color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:var(--mist);color:var(--ink);font:16px/1.6 "Helvetica Neue",Arial,system-ui,sans-serif}
[dir=rtl] body,[dir=rtl]{font-family:"Noto Naskh Arabic","Geeza Pro",Tahoma,sans-serif}
.wrap{max-width:620px;margin:0 auto;padding:28px max(18px,env(safe-area-inset-left)) 60px}
header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:28px}
.brand{font-family:Georgia,"Times New Roman",serif;letter-spacing:.3em;font-size:1.1rem}
.langs button{border:0;background:none;padding:6px 8px;font:inherit;font-size:.8rem;color:var(--soft);cursor:pointer}
.langs button[aria-current=true]{color:var(--ink);box-shadow:inset 0 -1px 0 var(--ink)}
h1{font-family:Georgia,"Times New Roman",serif;font-weight:400;font-size:2rem;line-height:1.15;margin:0 0 10px}
[dir=rtl] h1,[dir=rtl] h2{font-family:inherit}
h2{font-family:Georgia,serif;font-weight:400;font-size:1.35rem;margin:0}
.muted{color:var(--soft)}
.card{background:var(--paper);border:1px solid var(--line);padding:24px;margin-top:18px;display:grid;gap:14px}
.sejour{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;border-top:1px solid var(--ink);padding-top:14px;margin-top:14px}
.sejour b{display:block;font-weight:500}
label{display:grid;gap:6px;font-size:.92rem}
input,select{font:inherit;padding:12px;border:1px solid var(--line);border-radius:0;background:#fff;width:100%}
input:focus,select:focus{outline:1px solid var(--ink);border-color:var(--ink)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:520px){.row{grid-template-columns:1fr}}
.btn{display:block;width:100%;padding:16px;border:1px solid var(--ink);background:var(--ink);color:#fff;font:inherit;letter-spacing:.08em;cursor:pointer}
.btn:disabled{opacity:.5}
.btn.ghost{background:transparent;color:var(--ink)}
.err{color:var(--bad);font-size:.9rem}
.ok{color:var(--ok)}
.progress{display:flex;gap:6px}.progress span{flex:1;height:3px;background:var(--line)}.progress span.on{background:var(--ink)}
.secure{font-size:.82rem;color:var(--soft);border-top:1px solid var(--line);padding-top:14px}
.secure b{color:var(--ink);font-weight:500}
[hidden]{display:none!important}
</style>
</head>
<body>
<div class="wrap">
  <header><span class="brand"><?= h(AG_COURT) ?></span><div class="langs" role="group" aria-label="Langue"><button data-l="fr">FR</button><button data-l="en">EN</button><button data-l="ar" lang="ar">العربية</button></div></header>
  <main id="v"><p class="muted">…</p></main>
</div>
<script>
const T = {
 fr:{titre:"Préparons votre arrivée",intro:"La loi impose de déclarer chaque voyageur adulte aux autorités. Remplissez une fiche par personne : cela prend deux minutes.",arr:"Arrivée",dep:"Départ",
   nb:"Nombre de voyageurs adultes",fiche:"Fiche voyageur",sur:"sur",nom:"Nom",prenom:"Prénom",sexe:"Sexe",F:"Femme",M:"Homme",dn:"Date de naissance",ln:"Lieu de naissance",nat:"Nationalité",prof:"Profession",
   adr:"Adresse habituelle",pt:"Pièce d'identité",pn:"Numéro de la pièce",pd:"Date de délivrance",vd:"Venant de",aa:"Allant à",em:"E-mail (pour recevoir votre code d'accès)",tel:"Téléphone",
   photo:"Photo de la pièce d'identité",photoaide:"Prenez la pièce à plat, en entier, sans reflet.",envoyer:"Envoyer la fiche",envoi:"Envoi…",oblig:"Ce champ est obligatoire.",
   merci:"Merci, toutes les fiches sont reçues.",merci2:"Vous recevrez votre code d'accès dès que le logement sera prêt.",suivante:"Fiche suivante",
   secu:"<b>Vos données sont protégées.</b> Elles sont chiffrées, jamais transmises par messagerie, consultées seulement en cas de nécessité et effacées automatiquement à la fin du délai légal. La photo de votre pièce n'est jamais affichée à l'équipe.",
   lien:"Ce lien n'est plus valable. Contactez votre hôte.",choisir:"Choisir"},
 en:{titre:"Preparing your arrival",intro:"The law requires every adult guest to be registered with the authorities. Please complete one form per person: it takes two minutes.",arr:"Arrival",dep:"Departure",
   nb:"Number of adult guests",fiche:"Guest form",sur:"of",nom:"Surname",prenom:"First name",sexe:"Sex",F:"Female",M:"Male",dn:"Date of birth",ln:"Place of birth",nat:"Nationality",prof:"Occupation",
   adr:"Home address",pt:"Identity document",pn:"Document number",pd:"Date of issue",vd:"Coming from",aa:"Going to",em:"Email (to receive your access code)",tel:"Phone",
   photo:"Photo of the identity document",photoaide:"Lay the document flat, fully visible, without glare.",envoyer:"Send the form",envoi:"Sending…",oblig:"This field is required.",
   merci:"Thank you, all forms have been received.",merci2:"You will receive your access code as soon as the property is ready.",suivante:"Next form",
   secu:"<b>Your data is protected.</b> It is encrypted, never sent through messaging apps, viewed only when necessary and deleted automatically at the end of the legal period. The photo of your document is never shown to the team.",
   lien:"This link is no longer valid. Please contact your host.",choisir:"Select"},
 ar:{titre:"نحضّرو الوصول ديالك",intro:"القانون كيفرض التصريح بكل ضيف بالغ للسلطات. عمّر بطاقة لكل شخص، كاتاخد جوج دقايق.",arr:"الوصول",dep:"المغادرة",
   nb:"عدد الضيوف البالغين",fiche:"بطاقة الضيف",sur:"من",nom:"الاسم العائلي",prenom:"الاسم الشخصي",sexe:"الجنس",F:"أنثى",M:"ذكر",dn:"تاريخ الازدياد",ln:"مكان الازدياد",nat:"الجنسية",prof:"المهنة",
   adr:"العنوان الاعتيادي",pt:"وثيقة الهوية",pn:"رقم الوثيقة",pd:"تاريخ الإصدار",vd:"قادم من",aa:"متوجه إلى",em:"البريد الإلكتروني (باش يوصلك كود الدخول)",tel:"الهاتف",
   photo:"تصويرة وثيقة الهوية",photoaide:"حط الوثيقة مبسوطة، كاملة، بلا لمعان.",envoyer:"صيفط البطاقة",envoi:"كيتصيفط…",oblig:"هاد الخانة ضرورية.",
   merci:"شكرا، جميع البطائق توصلنا بيها.",merci2:"غادي يوصلك كود الدخول ملي يكون السكن واجد.",suivante:"البطاقة الجاية",
   secu:"<b>المعطيات ديالك محمية.</b> مشفّرة، عمرها ما كاتتصيفط فالواتساب، ما كاتّشافش غير إلا كانت ضرورة، وكاتتمسح أوتوماتيكيا فآخر الأجل القانوني. تصويرة الوثيقة عمرها ما كاتبان للفريق.",
   lien:"هاد الرابط ما بقاش صالح. تواصل مع المضيف ديالك.",choisir:"اختار"}
};
const TYPES = {fr:{cin:"Carte nationale d'identité",passeport:"Passeport",sejour:"Carte de séjour"},en:{cin:"National identity card",passeport:"Passport",sejour:"Residence permit"},ar:{cin:"البطاقة الوطنية",passeport:"جواز السفر",sejour:"بطاقة الإقامة"}};
const tok = new URLSearchParams(location.search).get("t") || "";
let L = (()=>{ try { return localStorage.getItem("orvia-l") || ""; } catch(e) { return ""; } })() || ((navigator.language||"fr").slice(0,2) === "ar" ? "ar" : (navigator.language||"fr").slice(0,2) === "en" ? "en" : "fr");
let I = null, err = "";
const $ = s => document.querySelector(s);
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const fd = iso => new Date(iso + "T12:00:00").toLocaleDateString(L === "ar" ? "ar-MA" : L === "en" ? "en-GB" : "fr-FR", {weekday:"long", day:"numeric", month:"long"});
async function call(action, body, isForm){
  const o = body ? {method:"POST", headers:{"X-Requested-With":"amaya"}, body: isForm ? body : JSON.stringify(body)} : {};
  if (body && !isForm) o.headers["Content-Type"] = "application/json";
  const r = await fetch("conciergerie_api.php?action=" + action + (body ? "" : "&t=" + encodeURIComponent(tok)), o);
  const d = await r.json().catch(() => ({})); if (!r.ok) throw new Error(d.error || "Erreur " + r.status); return d;
}
function setL(l){ L = l; try { localStorage.setItem("orvia-l", l); } catch(e) {} document.documentElement.lang = l; document.documentElement.dir = l === "ar" ? "rtl" : "ltr";
  document.querySelectorAll(".langs button").forEach(b => b.setAttribute("aria-current", b.dataset.l === l)); render(); }
document.querySelectorAll(".langs button").forEach(b => b.onclick = () => setL(b.dataset.l));
function render(){
  const t = T[L];
  if (!I) { $("#v").innerHTML = `<p class="muted">…</p>`; return; }
  if (I.erreur) { $("#v").innerHTML = `<h1>${esc(t.titre)}</h1><div class="card"><p class="err">${esc(t.lien)}</p></div>`; return; }
  const fini = I.recues >= I.attendues;
  const bar = Array.from({length:I.attendues}, (_, i) => `<span class="${i < I.recues ? "on" : ""}"></span>`).join("");
  let h = `<h1>${esc(t.titre)}</h1><p class="muted">${esc(t.intro)}</p>
    <div class="card"><h2>${esc(I.logement)}</h2><div class="muted">${esc(I.ville)}</div>
      <div class="sejour"><div><span class="muted">${t.arr}</span><b>${esc(fd(I.arrivee))}</b></div><div><span class="muted">${t.dep}</span><b>${esc(fd(I.depart))}</b></div></div>
      <label>${t.nb}<input id="nb" type="number" min="${Math.max(1, I.recues)}" max="20" value="${I.attendues}" inputmode="numeric"></label>
      <div class="progress">${bar}</div></div>`;
  if (fini) h += `<div class="card"><p class="ok"><b>${esc(t.merci)}</b></p><p class="muted">${esc(t.merci2)}</p></div>`;
  else {
    const types = Object.entries(TYPES[L]).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join("");
    h += `<form class="card" id="f" novalidate><h2>${t.fiche} ${I.recues + 1} ${t.sur} ${I.attendues}</h2>
      <div class="row"><label>${t.nom}<input name="nom" autocomplete="family-name"></label><label>${t.prenom}<input name="prenom" autocomplete="given-name"></label></div>
      <div class="row"><label>${t.sexe}<select name="sexe"><option value="">${t.choisir}</option><option value="F">${t.F}</option><option value="M">${t.M}</option></select></label><label>${t.nat}<input name="nationalite"></label></div>
      <div class="row"><label>${t.dn}<input name="date_naissance" type="date"></label><label>${t.ln}<input name="lieu_naissance"></label></div>
      <label>${t.adr}<input name="adresse" autocomplete="street-address"></label>
      <label>${t.prof}<input name="profession"></label>
      <div class="row"><label>${t.pt}<select name="piece_type"><option value="">${t.choisir}</option>${types}</select></label><label>${t.pn}<input name="piece_numero" autocapitalize="characters"></label></div>
      <div class="row"><label>${t.pd}<input name="piece_delivrance" type="date"></label><label>${t.vd}<input name="venant_de"></label></div>
      <label>${t.aa}<input name="allant_a"></label>
      <div class="row"><label>${t.em}<input name="email" type="email" autocomplete="email"></label><label>${t.tel}<input name="telephone" type="tel" autocomplete="tel"></label></div>
      ${I.piece ? `<label>${t.photo}<input name="piece" type="file" accept="image/*" capture="environment"><span class="muted" style="font-size:.84rem">${t.photoaide}</span></label>` : ""}
      <p class="err" id="err" ${err ? "" : "hidden"}>${esc(err)}</p>
      <button class="btn" id="go">${t.envoyer}</button>
      <div class="secure">${t.secu}</div></form>`;
  }
  $("#v").innerHTML = h;
  $("#nb").onchange = async e => { try { const r = await call("voy_nb", {t:tok, nb:+e.target.value}); I.attendues = r.attendues; render(); } catch(x) { alertErr(x.message); } };
  const f = $("#f"); if (f) f.onsubmit = async ev => {
    ev.preventDefault(); const b = $("#go"); b.disabled = true; b.textContent = t.envoi;
    f.querySelectorAll("[aria-invalid]").forEach(x => x.removeAttribute("aria-invalid"));
    const data = new FormData(f); data.append("t", tok);
    try { const r = await call("voy_fiche", data, true); I.recues = r.recues; I.attendues = r.attendues; err = ""; render(); window.scrollTo(0, 0); }
    catch(x) { const m = /^champ:(\w+)/.exec(x.message); if (m) { const el = f.querySelector(`[name=${m[1]}]`); if (el) { el.setAttribute("aria-invalid", "true"); el.focus(); } err = t.oblig + (el ? " (" + (el.closest("label")?.firstChild?.textContent || "").trim() + ")" : ""); }
      else err = x.message; const e = $("#err"); e.textContent = err; e.hidden = false; b.disabled = false; b.textContent = t.envoyer; }
  };
}
function alertErr(m){ const e = $("#err"); if (e) { e.textContent = m; e.hidden = false; } }
(async () => { try { I = await call("voy_info"); } catch(e) { I = {erreur:true}; } setL(L); })();
</script>
</body>
</html>
