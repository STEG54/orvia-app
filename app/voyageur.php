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
.stars{display:flex;gap:6px}.stars button{border:1px solid var(--line);background:#fff;font-size:1.6rem;line-height:1;padding:6px 10px;color:#c9c5bd;cursor:pointer}.stars button.on{color:var(--ink)}
.srvl{display:grid;grid-template-columns:1fr auto 70px;gap:10px;align-items:center;border-top:1px solid var(--line);padding-top:10px}.srvp{white-space:nowrap}
textarea{font:inherit;padding:12px;border:1px solid var(--line);width:100%}
.etape{margin:0;display:grid;gap:6px}.etape img{width:100%;max-height:340px;object-fit:cover;display:block}.secret{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:baseline;border:1px solid var(--line);padding:12px}.secret span{color:var(--soft);font-size:.85rem}.secret b{font-size:1.25rem;letter-spacing:.06em}
.bulle{background:var(--mist,#f4f3f1);padding:10px 12px;max-width:88%;white-space:pre-line}.bulle span{display:block;font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:var(--soft);margin-bottom:2px}.bulle.moi{margin-inline-start:auto;background:#fff;border:1px solid var(--line)}
#okbar{position:fixed;left:50%;bottom:16px;transform:translateX(-50%);background:var(--ink);color:#fff;padding:12px 16px;max-width:92%;z-index:9}#okbar.bad{background:var(--bad)}
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
const T2 = {
 fr:{arrTitre:"Votre arrivée",mode:"Vous arrivez en",avion:"Avion",voiture:"Voiture",train:"Train",autre:"Autre",vol:"Numéro de vol",heure:"Heure d'arrivée prévue",wa:"Votre numéro WhatsApp (avec l'indicatif)",enreg:"Enregistrer",enregOk:"C'est noté, merci.",
   srvTitre:"À votre arrivée",srvIntro:"Nous pouvons tout préparer avant votre arrivée.",commander:"Commander",commandeOk:"C'est commandé : tout sera prêt à votre arrivée.",dejaCmd:"Déjà commandé",paiement:"Réglé selon les modalités indiquées par votre hôte.",
   avArr:"Tout est-il en ordre ?",avArrQ:"Votre logement était-il impeccable à votre arrivée ?",avSej:"Votre séjour",avLog:"Le logement",avAcc:"L'accueil",com:"Un commentaire (facultatif)",photoPb:"Une photo (facultatif)",avEnv:"Envoyer mon avis",avMerci:"Merci pour votre avis.",
   qTitre:"Une question ?",qIntro:"Posez-la ici, à toute heure. La conciergerie suit chaque échange.",qPh:"Votre question",qEnv:"Envoyer",qTransmis:"Votre question est transmise à la conciergerie, qui vous répond ici ou par WhatsApp.",vous:"Vous",assistant:"Assistant",conc:"Conciergerie",
   livTitre:"Comment arriver",maps:"Itinéraire Google Maps",waze:"Waze",repere:"Repère",parking:"Stationnement",codesTitre:"Pour entrer",code:"Code",wifi:"Wi-Fi",reseau:"Réseau",mdp:"Mot de passe",codesPlusTard:"Le code, les consignes d'entrée et le Wi-Fi s'afficheront ici le jour de votre arrivée, dès que le logement sera prêt.",appeler:"Sur place, un souci ?",departT:"Au départ",copier:"Copier",copie:"Copié",
   pb:"Un problème pendant le séjour ?",pbTxt:"Décrivez-le, nous intervenons rapidement.",pbEnv:"Signaler",pbOk:"C'est transmis. Nous revenons vers vous très vite."},
 en:{arrTitre:"Your arrival",mode:"You are arriving by",avion:"Plane",voiture:"Car",train:"Train",autre:"Other",vol:"Flight number",heure:"Expected arrival time",wa:"Your WhatsApp number (with country code)",enreg:"Save",enregOk:"Noted, thank you.",
   srvTitre:"On your arrival",srvIntro:"We can have everything ready before you arrive.",commander:"Order",commandeOk:"Ordered: everything will be ready when you arrive.",dejaCmd:"Already ordered",paiement:"Paid as specified by your host.",
   avArr:"Is everything in order?",avArrQ:"Was your accommodation spotless when you arrived?",avSej:"Your stay",avLog:"The accommodation",avAcc:"The welcome",com:"A comment (optional)",photoPb:"A photo (optional)",avEnv:"Send my review",avMerci:"Thank you for your review.",
   qTitre:"A question?",qIntro:"Ask it here, at any time. The concierge team follows every exchange.",qPh:"Your question",qEnv:"Send",qTransmis:"Your question has been passed on to the concierge team, who will reply here or on WhatsApp.",vous:"You",assistant:"Assistant",conc:"Concierge",
   livTitre:"Getting there",maps:"Google Maps directions",waze:"Waze",repere:"Landmark",parking:"Parking",codesTitre:"Getting in",code:"Code",wifi:"Wi-Fi",reseau:"Network",mdp:"Password",codesPlusTard:"The code, entry instructions and Wi-Fi will appear here on your arrival day, as soon as the accommodation is ready.",appeler:"Any issue on site?",departT:"When you leave",copier:"Copy",copie:"Copied",
   pb:"A problem during your stay?",pbTxt:"Describe it, we will act quickly.",pbEnv:"Report",pbOk:"Sent. We will get back to you very soon."},
 ar:{arrTitre:"الوصول ديالك",mode:"جاي ب",avion:"الطيارة",voiture:"الطوموبيل",train:"التران",autre:"شي حاجة أخرى",vol:"رقم الرحلة",heure:"ساعة الوصول المتوقعة",wa:"رقم الواتساب ديالك (مع رمز البلاد)",enreg:"سجّل",enregOk:"تسجّل، شكرا.",
   srvTitre:"ملي توصل",srvIntro:"نقدرو نوجّدو ليك كلشي قبل ما توصل.",commander:"طلب",commandeOk:"تطلب: كلشي غادي يكون واجد ملي توصل.",dejaCmd:"مطلوب من قبل",paiement:"الخلاص حسب الطريقة اللي قال المضيف ديالك.",
   avArr:"واش كلشي مزيان؟",avArrQ:"واش السكن كان نقي مزيان ملي وصلتي؟",avSej:"الإقامة ديالك",avLog:"السكن",avAcc:"الاستقبال",com:"ملاحظة (اختياري)",photoPb:"تصويرة (اختياري)",avEnv:"صيفط الرأي ديالي",avMerci:"شكرا على الرأي ديالك.",
   qTitre:"عندك شي سؤال؟",qIntro:"سولنا هنا، فأي وقت. الكونسيرجري كاتتبع كل رسالة.",qPh:"السؤال ديالك",qEnv:"صيفط",qTransmis:"السؤال ديالك وصل للكونسيرجري، غادي يجاوبوك هنا ولا فالواتساب.",vous:"نتا",assistant:"المساعد",conc:"الكونسيرجري",
   livTitre:"كيفاش توصل",maps:"الطريق ف Google Maps",waze:"Waze",repere:"علامة",parking:"الباركينغ",codesTitre:"باش تدخل",code:"الكود",wifi:"الويفي",reseau:"الشبكة",mdp:"كلمة السر",codesPlusTard:"الكود، طريقة الدخول والويفي غادي يبانو هنا نهار الوصول ديالك، ملي يكون السكن واجد.",appeler:"كاين شي مشكل فالبلاصة؟",departT:"ملي تخرج",copier:"نسخ",copie:"تنسخ",
   pb:"كاين شي مشكل فالإقامة؟",pbTxt:"شرحو لينا، غادي نتدخلو بسرعة.",pbEnv:"صيفط",pbOk:"توصلنا بيه. غادي نرجعو ليك دغيا."}
};
const etoiles = (nom) => `<div class="stars" data-n="${nom}">${[1,2,3,4,5].map(i => `<button type="button" data-v="${i}" aria-label="${i}/5">★</button>`).join("")}</div>`;
function extras(){
  const t = T2[L], u = T[L], h = []; const V = I.livret;
  if (V) h.push(`<div class="card"><h2>${t.livTitre}</h2>${V.adresse || V.ville ? `<div>${esc(V.adresse)} ${esc(V.ville)}</div>` : ""}
      ${V.carte && V.carte.maps ? `<div class="row"><a class="btn" href="${esc(V.carte.maps)}" target="_blank" rel="noopener">${t.maps}</a><a class="btn ghost" href="${esc(V.carte.waze)}" target="_blank" rel="noopener">${t.waze}</a></div>` : ""}
      ${V.repere ? `<div><b>${t.repere} :</b> ${esc(V.repere)}</div>` : ""}${V.parking ? `<div><b>${t.parking} :</b> ${esc(V.parking)}</div>` : ""}
      ${V.etapes.map((e, i) => `<figure class="etape">${e.photo ? `<img src="${esc(e.photo)}" alt="" loading="lazy">` : ""}<figcaption><b>${i + 1}.</b> ${esc(e.texte)}</figcaption></figure>`).join("")}
      ${V.codes ? `<h3>${t.codesTitre}</h3>${V.code ? `<div class="secret"><span>${t.code}</span><b>${esc(V.code)}</b></div>` : ""}${V.serrure ? `<div>${esc(V.serrure)}</div>` : ""}${V.acces ? `<div style="white-space:pre-line">${esc(V.acces)}</div>` : ""}
        ${V.wifi_nom || V.wifi_mdp ? `<div class="secret"><span>${t.wifi}</span><b>${esc(V.wifi_nom)}</b>${V.wifi_mdp ? `<span>${t.mdp}</span><b>${esc(V.wifi_mdp)}</b><button type="button" class="btn ghost" data-cp="${esc(V.wifi_mdp)}">${t.copier}</button>` : ""}</div>` : ""}
        ${V.depart_consignes ? `<div><b>${t.departT} :</b> ${esc(V.depart_consignes)}</div>` : ""}` : `<p class="muted">${t.codesPlusTard}</p>`}
      ${V.urgence ? `<div class="muted">${t.appeler} <a href="tel:${esc(V.urgence.replace(/[^+0-9]/g, ""))}">${esc(V.urgence)}</a></div>` : ""}</div>`);
  if (!I.termine || I.en_sejour || (I.messages || []).length) h.push(`<form class="card" id="q"><h2>${t.qTitre}</h2><p class="muted">${t.qIntro}</p>
      ${(I.messages || []).map(m => `<div class="bulle ${m.auteur === "voyageur" ? "moi" : ""}"><span>${m.auteur === "voyageur" ? t.vous : m.auteur === "assistant" ? t.assistant : t.conc}</span>${esc(m.texte)}</div>`).join("")}
      <textarea name="question" rows="2" placeholder="${t.qPh}" maxlength="1000"></textarea><button class="btn">${t.qEnv}</button></form>`);
  const nomS = s => (L === "ar" && s.nom_ar) || (L === "en" && s.nom_en) || s.nom;
  if (!I.termine) h.push(`<form class="card" id="arr"><h2>${t.arrTitre}</h2><div class="row"><label>${t.mode}<select name="mode"><option value="">${u.choisir}</option>${["avion","voiture","train","autre"].map(k => `<option value="${k}" ${I.arrivee_mode === k ? "selected" : ""}>${t[k]}</option>`).join("")}</select></label>
      <label>${t.vol}<input name="vol" value="${esc(I.vol)}" autocapitalize="characters" placeholder="AT 773"></label></div>
      <div class="row"><label>${t.heure}<input name="heure" type="time" value="${esc(I.arrivee_heure)}"></label><label>${t.wa}<input name="whatsapp" type="tel" value="${esc(I.whatsapp)}" placeholder="+33 6 12 34 56 78"></label></div>
      <button class="btn ghost">${t.enreg}</button></form>`);
  if (I.services.length) h.push(`<form class="card" id="srv"><h2>${t.srvTitre}</h2><p class="muted">${t.srvIntro}</p>
      ${I.commandes.length ? `<div class="ok">${t.dejaCmd} : ${I.commandes.map(c => esc(c.quantite + " × " + nomS(c))).join(", ")}</div>` : ""}
      ${I.services.map(s => `<div class="srvl"><div><b>${esc(nomS(s))}</b>${s.description && L === "fr" ? `<div class="muted" style="font-size:.88rem">${esc(s.description)}</div>` : ""}</div><div class="srvp">${(+s.prix).toLocaleString("fr-FR")} DH</div><input type="number" min="0" max="20" value="0" data-s="${s.id}" aria-label="${esc(nomS(s))}"></div>`).join("")}
      <button class="btn">${t.commander}</button><span class="muted" style="font-size:.82rem">${t.paiement}</span></form>`);
  if (I.avis.includes("arrivee")) h.push(`<form class="card" id="av1"><h2>${t.avArr}</h2><p>${t.avArrQ}</p>${etoiles("proprete")}<label>${t.com}<input name="commentaire"></label><label>${t.photoPb}<input name="photo" type="file" accept="image/*" capture="environment"></label><button class="btn">${t.avEnv}</button></form>`);
  if (I.en_sejour) h.push(`<form class="card" id="pb"><h2>${t.pb}</h2><label>${t.pbTxt}<textarea name="description" rows="3"></textarea></label><label>${t.photoPb}<input name="photo" type="file" accept="image/*" capture="environment"></label><button class="btn ghost">${t.pbEnv}</button></form>`);
  if (I.avis.includes("sejour")) h.push(`<form class="card" id="av2"><h2>${t.avSej}</h2><p>${t.avLog}</p>${etoiles("logement")}<p>${t.avAcc}</p>${etoiles("accueil")}<label>${t.com}<input name="commentaire"></label><button class="btn">${t.avEnv}</button></form>`);
  return h.join("");
}
function bindExtras(){
  const t = T2[L];
  document.querySelectorAll("[data-cp]").forEach(b => b.onclick = async () => { try { await navigator.clipboard.writeText(b.dataset.cp); b.textContent = t.copie; } catch(e) {} });
  document.querySelectorAll(".stars").forEach(g => g.querySelectorAll("button").forEach(b => b.onclick = () => { g.dataset.val = b.dataset.v; g.querySelectorAll("button").forEach(x => x.classList.toggle("on", +x.dataset.v <= +b.dataset.v)); }));
  const envoi = (id, action, prep, okMsg) => { const f = $("#" + id); if (!f) return; f.onsubmit = async ev => { ev.preventDefault(); const b = f.querySelector("button:not([data-v])"); b.disabled = true;
    try { const r = await prep(f); const res = await call(action, r.fd || r, !!r.fd); I = await call("voy_info"); render(); const msg = okMsg || (res.transmis && !res.reponse ? t.qTransmis : ""); if (msg) setTimeout(() => alertOk(msg), 50); if (id === "q") $("#q").scrollIntoView({block:"end"}); } catch(x) { b.disabled = false; alertOk(x.message.replace(/^champ:.*/, T[L].oblig), true); } }; };
  envoi("q", "voy_question", f => ({t:tok, question:f.question.value}), "");
  envoi("arr", "voy_arrivee", f => Object.assign({t:tok}, Object.fromEntries(new FormData(f))), t.enregOk);
  envoi("srv", "voy_commande", f => { const s = {}; f.querySelectorAll("[data-s]").forEach(i => { if (+i.value > 0) s[i.dataset.s] = +i.value; }); return {t:tok, services:s}; }, t.commandeOk);
  const avis = (moment) => f => { const fd = new FormData(f); fd.append("t", tok); fd.append("moment", moment); f.querySelectorAll(".stars").forEach(g => { if (g.dataset.val) fd.append(g.dataset.n, g.dataset.val); }); return {fd}; };
  envoi("av1", "voy_avis", avis("arrivee"), t.avMerci); envoi("av2", "voy_avis", avis("sejour"), t.avMerci);
  envoi("pb", "voy_probleme", f => { const fd = new FormData(f); fd.append("t", tok); return {fd}; }, t.pbOk);
}
function alertOk(m, bad){ let e = $("#okbar"); if (!e) { e = document.createElement("div"); e.id = "okbar"; document.body.append(e); } e.textContent = m; e.className = bad ? "bad" : ""; e.hidden = false; clearTimeout(alertOk.h); alertOk.h = setTimeout(() => e.hidden = true, 4000); }
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
  if (I.termine) {}
  else if (fini) h += `<div class="card"><p class="ok"><b>${esc(t.merci)}</b></p><p class="muted">${esc(t.merci2)}</p></div>`;
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
  h += extras();
  $("#v").innerHTML = h; bindExtras();
  if ($("#nb")) $("#nb").onchange = async e => { try { const r = await call("voy_nb", {t:tok, nb:+e.target.value}); I.attendues = r.attendues; render(); } catch(x) { alertErr(x.message); } };
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
