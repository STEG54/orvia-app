// Application intervenant(e) : un compte rendu par journée d'animation.
function installHint(){
  let off = false; try { off = localStorage.getItem("amy_install_off") === "1"; } catch(e) {}
  if (isStandalone() || off) return "";
  return `<div class="card install"><div style="display:flex;gap:12px;align-items:center"><img src="assets/icon-180.png" alt="" width="48" height="48" style="border-radius:11px">
    <div><b>Installez l'appli sur votre téléphone</b><div class="muted">Vous la retrouverez d'un clic sur votre écran d'accueil.</div></div></div>
    <div class="btns" style="margin:0"><button class="primary" data-install>Installer</button><button class="ghost" id="inst-off">Plus tard</button></div></div>`;
}
function bindInstall(){
  const o = document.getElementById("inst-off");
  if (o) o.onclick = () => { try { localStorage.setItem("amy_install_off", "1"); } catch(e) {} o.closest(".install").remove(); render(); };
}
const S = { L:null, me:null, d:null, photos:[], view:"home", mine:[], uploading:0, fx:{key:"", v:null} };

function newToken(){ const a = new Uint8Array(16); crypto.getRandomValues(a); return [...a].map(x=>x.toString(16).padStart(2,"0")).join(""); }
function today(){ const d=new Date(); d.setMinutes(d.getMinutes()-d.getTimezoneOffset()); return d.toISOString().slice(0,10); }
function blank(){ return { token:newToken(), zone:S.me?.zone||"", magasin_id:"", magasin_autre:"", ville_autre:"", date:today(), frequentation:"", concurrence:[], concurrence_autre:"",
  offres:"", staff:"", faits:"", marques:[{slot:0, marque_id:"", ca:"", objectif:"", sub:{}, ruptures:"", testeurs:"", reactions:""}], next:1 }; }
const KEY = () => "amy_draft_" + (S.me ? S.me.id : "x");
function saveLocal(){ if (S.L && S.L.apercu) return; try { localStorage.setItem(KEY(), JSON.stringify(S.d)); } catch(e) {} }
function saveDraft(){ if (S.L.apercu) return setSt("Aperçu : rien n'est enregistré"); S.d.at = Date.now(); saveLocal(); setSt("Enregistrement…"); clearTimeout(saveDraft.t); saveDraft.t = setTimeout(syncDraft, 1200); }
const hhmm = t => new Date(t).toLocaleTimeString("fr-FR", {hour:"2-digit", minute:"2-digit"}).replace(":", "h");
function setSt(t, warn){ S.st = t; S.stWarn = !!warn; const el = document.getElementById("draft-st"); if (el) { el.textContent = t; el.classList.toggle("warn", !!warn); } }
async function syncDraft(){
  if (!S.d || !S.d.at) return;
  try {
    const r = await api("save_draft", {d:S.d, at:S.d.at});
    if (r.sent) { try { localStorage.removeItem(KEY()); } catch(e) {} S.d = blank(); S.photos = []; setSt(""); render(); return; }
    if (r.newer) return pullDraft(true);
    setSt("Brouillon enregistré à " + hhmm(S.d.at) + " · vous pouvez fermer l'appli et revenir plus tard");
  } catch(e) { setSt("Brouillon gardé sur ce téléphone · il sera enregistré dès que le réseau revient", true); }
}
async function pullDraft(announce){
  try {
    const r = await api("get_draft");
    if (r.d && r.d.token && r.at > (S.d?.at || 0)) {
      S.d = Object.assign(blank(), r.d, {at:r.at}); saveLocal(); await refreshPhotos();
      S.st = "Brouillon enregistré à " + hhmm(r.at); S.stWarn = false; if (S.view === "form") render();
      if (announce) toast("Brouillon mis à jour depuis votre autre appareil.");
      return true;
    }
  } catch(e) {}
  return false;
}
addEventListener("online", () => { if (S.d && S.stWarn) syncDraft(); });
document.addEventListener("visibilitychange", () => { if (document.visibilityState === "visible" && S.me && S.view === "form" && !S.uploading) pullDraft(true); });
function loadDraft(){ try { const x = JSON.parse(localStorage.getItem(KEY()) || "null"); return x && x.token ? x : null; } catch(e) { return null; } }

/** Bandeau de l'aperçu administrateur. */
function apercuBar(){
  if (document.getElementById("apercu-bar")) return;
  const b = document.createElement("div"); b.id = "apercu-bar";
  b.innerHTML = `<span><b>Aperçu</b> · l'appli de ${esc(S.me.prenom + " " + String(S.me.nom || "").toUpperCase())}, en lecture seule : rien n'est enregistré ni envoyé.</span><button type="button" id="apercu-x">Quitter l'aperçu</button>`;
  document.body.prepend(b);
  $("#apercu-x").onclick = async () => { try { await api("apercu_stop", {}); } catch(e) {} location.href = "admin.php"; };
}
async function boot(){
  try {
    S.L = await api("session");
    S.me = S.L.animatrice;
    if (!S.me) return vLogin();
    if (!+S.me.rgpd) return vConsent();
    $("#who").innerHTML = esc(S.me.prenom) + "<br><span style='opacity:.7'>" + esc(S.me.zone) + "</span>" + (isStandalone() ? "" : `<br><a class="top-inst" href="installer.php" data-install>Installer l'appli</a>`);
    if (S.L.apercu) apercuBar();
    S.d = (!S.L.apercu && loadDraft()) || blank();
    const fromServer = await pullDraft(false);
    if (!fromServer && S.d.at) syncDraft();
    await refreshPhotos();
    render(); scrollTo(0, 0);
    if (!S.L.apercu) ouvrirAncre(); // ouverture depuis une notification
  } catch(e) { $("#view").innerHTML = `<div class="card"><b>Connexion impossible</b><div class="muted">${esc(e.message)}</div><button id="retry">Réessayer</button></div>`; $("#retry").onclick = boot; }
}

function vConsent(){
  $("#view").innerHTML = `<div class="card login accent"><h1>Vos données</h1>
    <div class="muted">Avant de continuer, merci de prendre connaissance de la façon dont ${AGN} utilise vos données et vos photos.</div>
    <label class="ck"><input type="checkbox" id="rg1"><span>J'ai lu <a href="confidentialite.php" target="_blank" rel="noopener">l'information sur mes données personnelles</a>.</span></label>
    <label class="ck"><input type="checkbox" id="rg2"><span>J'autorise ${AGN} à transmettre les photos de mes interventions aux propriétaires des logements concernés.</span></label>
    <button class="primary" id="go">Continuer</button></div>`;
  $("#go").onclick = async () => { if (!$("#rg1").checked || !$("#rg2").checked) return toast("Merci de cocher les deux cases.");
    try { await api("consent", {rgpd_info:1, rgpd_image:1}); boot(); } catch(e) { toast(e.message); } };
}
function vLogin(){
  if (typeof AMAYA_DEMO !== "undefined" && AMAYA_DEMO) { location.href = "demo.php"; return; }
  $("#who").textContent = "";
  const zones = (S.L && S.L.zones) || [];
  let zone = zones.length === 1 ? zones[0] : "";
  $("#view").innerHTML = `<div class="card login accent"><h1>Bienvenue</h1>
    <div class="muted">${AG} est l'application de l'équipe terrain de ${AGN} : vos missions, les accès des logements et les photos de vos interventions, depuis votre téléphone.</div>
    <div class="hint">Cette inscription ne se fait qu'une fois : ce téléphone se souviendra de vous.</div>
    <label>Code ${AG}<input id="code" autocapitalize="characters" autocomplete="off" placeholder="Transmis par votre responsable"></label>
    <div class="row"><label>Prénom<input id="prenom" autocomplete="given-name"></label><label>Nom<input id="nom" autocomplete="family-name"></label></div>
    <label>Téléphone portable<input id="tel" type="tel" inputmode="tel" autocomplete="tel" placeholder="06 12 34 56 78"></label>
    <label>Adresse e-mail<input id="email" type="email" inputmode="email" autocomplete="email" placeholder="Pour recevoir votre code de connexion"></label>
    <div${zones.length === 1 ? " hidden" : ""}><div class="muted" style="margin-bottom:6px">Votre zone</div><div class="chips" id="lzone">${zones.map(z => `<button type="button" class="chip" aria-pressed="false" data-v="${z}">${z}</button>`).join("")}</div></div>
    <label class="ck"><input type="checkbox" id="rg1"><span>J'ai lu <a href="confidentialite.php" target="_blank" rel="noopener">l'information sur mes données personnelles</a>.</span></label>
    <label class="ck"><input type="checkbox" id="rg2"><span>J'autorise ${AGN} à transmettre les photos de mes interventions aux propriétaires des logements concernés.</span></label>
    <button class="primary" id="go">Entrer</button>
    <div class="login-links"><a href="installer.php" data-install>Installer l'appli sur mon téléphone</a><a href="mailto:${CONTACT}">Contacter ${AG}</a></div></div>`;
  // Identité déjà saisie sur ce téléphone : tout est prérempli, il suffit d'appuyer sur « Entrer ».
  try { const m = JSON.parse(localStorage.getItem("amy_moi") || "null"); if (m) { $("#code").value = m.code || ""; $("#prenom").value = m.prenom || ""; $("#nom").value = m.nom || ""; $("#tel").value = m.telephone || ""; $("#email").value = m.email || "";
    if (m.zone) { zone = m.zone; document.querySelectorAll("#lzone .chip").forEach(c => c.setAttribute("aria-pressed", c.dataset.v === zone)); } $("#rg1").checked = true; $("#rg2").checked = true; } } catch(e) {}
  $("#lzone").onclick = e => { const b = e.target.closest(".chip"); if (!b) return; zone = b.dataset.v; document.querySelectorAll("#lzone .chip").forEach(c => c.setAttribute("aria-pressed", c === b)); };
  const go = async () => {
    const body = {code:$("#code").value, prenom:$("#prenom").value.trim(), nom:$("#nom").value.trim(), telephone:$("#tel").value, email:$("#email").value.trim(), zone, rgpd_info:$("#rg1").checked ? 1 : 0, rgpd_image:$("#rg2").checked ? 1 : 0};
    if (!body.code) return toast(("Saisissez le code " + AG + "."));
    if (!body.prenom || !body.nom) return toast("Indiquez votre prénom et votre nom.");
    if (body.telephone.replace(/\D/g, "").length < 9) return toast("Indiquez votre numéro de portable complet.");
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(body.email)) return toast("Indiquez votre adresse e-mail.");
    if (!zone) return toast("Choisissez votre zone.");
    if (!body.rgpd_info || !body.rgpd_image) return toast("Merci de cocher les deux cases sur vos données et vos photos.");
    $("#go").disabled = true;
    try { const r = await api("login", body);
      try { const {code, prenom, nom, telephone, email} = body; localStorage.setItem("amy_moi", JSON.stringify({code, prenom, nom, telephone, email, zone})); } catch(e) {}
      if (r.otp) return vCode(r, body);
      fini(r); } catch(e) { toast(e.message); $("#go").disabled = false; }
  };
  // Le téléphone garde son jeton : plus jamais de code à saisir sur cet appareil, même si le navigateur efface ses cookies.
  const fini = r => { try { if (r.rem) localStorage.setItem("amy_rem", r.rem); } catch(e) {} boot(); };
  // 2e étape : le code reçu par e-mail (protège les documents de chacun).
  const vCode = (r, body) => {
    $("#view").innerHTML = `<div class="card login accent"><h1>Votre code</h1>
      <div class="muted">Pour protéger vos documents, nous venons d'envoyer un code à 6 chiffres à <b>${esc(r.vers)}</b>${r.autre ? ", l'adresse e-mail déjà enregistrée sur votre compte" : ""}. Il est valable 15 minutes.</div>
      <label>Code reçu par e-mail<input id="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••" style="font-size:26px;letter-spacing:8px;text-align:center"></label>
      <button class="primary" id="otp-go">Valider</button>
      <div class="hint">Pensez à regarder dans vos courriers indésirables. Ce code n'est demandé qu'une fois par téléphone.</div>
      <div class="login-links"><a href="#" id="otp-new">Recevoir un nouveau code</a><a href="#" id="otp-back">Modifier mes informations</a></div>
      ${r.autre ? `<div class="hint">Vous n'avez plus accès à cette adresse ? Contactez ${AGN} : <a href="mailto:${CONTACT}">${CONTACT}</a>.</div>` : ""}</div>`;
    const ok = async () => { const c = $("#otp").value.replace(/\D/g, ""); if (c.length !== 6) return toast("Saisissez les 6 chiffres du code.");
      $("#otp-go").disabled = true; try { fini(await api("login_code", {code:c})); } catch(e) { toast(e.message); $("#otp-go").disabled = false; } };
    $("#otp-go").onclick = ok; $("#otp").oninput = () => { if ($("#otp").value.replace(/\D/g, "").length === 6) ok(); }; $("#otp").focus();
    $("#otp-new").onclick = async e => { e.preventDefault(); try { await api("login", body); toast("Nouveau code envoyé"); } catch(x) { toast(x.message); } };
    $("#otp-back").onclick = e => { e.preventDefault(); vLogin(); };
  };
  $("#go").onclick = go;
}

async function refreshPhotos(){ try { S.photos = (await api("photos", null, "token=" + S.d.token)).photos; } catch(e) { S.photos = []; } }

function render(){
  if (S.view === "cz" || S.view === "home") return czHome();
  if (S.view === "czm") return czMis();
  if (S.view === "home") return vHome();
  if (S.view === "mine") return vMine();
  if (S.view === "plan") return vPlan();
  if (S.view === "rel") return vRel();
  if (S.view === "ct") return vCt();
  if (S.view === "of") return vOf();
  if (S.view === "formations") return vFormations();
  if (S.view === "fiche") return vFiche();
  if (S.view === "docs") return vDocs();
  if (S.view === "dispo") return vDispo();
  if (S.view === "ndf") return vNdf();
  if (S.view === "evt") return vEvt();
  if (S.view === "mc") return vMc();
  if (S.view === "fae") return vFae();
  const d = S.d, L = S.L;
  const mags = L.magasins.filter(m => m.zone === d.zone);
  const devise = L.devises[d.zone] || "EUR", sym = SYM[devise] || devise;
  const m = d.marques[0], client = L.marques.find(x => String(x.id) === String(m.marque_id));
  const photosOf = slot => S.photos.filter(p => p.slot === slot);
  const RAYON = 4, nRayon = photosOf("m0").length, rayFull = nRayon >= RAYON;
  const del = p => `<div class="thumb"><img src="${p.url}" alt=""><button data-del="${p.id}" aria-label="Supprimer">✕</button></div>`;
  const shoot = (slot, label, cam, off) => `<label class="shoot${off ? " off" : ""}">${label}<input type="file" accept="image/*" capture="${cam}" data-slot="${slot}" ${off ? "disabled" : ""}></label>`;
  const gallery = (slot, off, multi) => `<label class="shoot alt${off ? " off" : ""}">Galerie<input type="file" accept="image/*" ${multi ? "multiple" : ""} data-slot="${slot}" ${off ? "disabled" : ""}></label>`;
  const selfie = photosOf("animatrice")[0];
  const money2 = f => `inputmode="decimal" placeholder="${f === "ca" ? "Ex. 1250 ou 1250,50" : "Si vous en avez un"}"`;
  // Objectif fixé par l'agence pour cette marque, cette date et ce point de vente : affiché, non modifiable.
  const fkey = client ? [m.marque_id, d.date, d.magasin_id].join("|") : "";
  if (fkey && S.fx.key !== fkey) { S.fx = {key:fkey, v:null}; api("objectif_fixe", null, new URLSearchParams({marque_id:m.marque_id, date:d.date, magasin_id:/^\d+$/.test(String(d.magasin_id)) ? d.magasin_id : ""}).toString())
    .then(r => { if (S.fx.key === fkey && r.fixe) { S.fx.v = r.fixe; if (!S.view || S.view === "form") render(); } }).catch(() => {}); }
  const fx = fkey && S.fx.key === fkey ? S.fx.v : null;
  if (fx) { m.objectif = fx.montant == null ? "" : String(fx.montant).replace(".", ","); (client.sous || []).forEach(n => { m.sub = m.sub || {}; m.sub[n] = m.sub[n] || {}; m.sub[n].objectif = fx.sub && fx.sub[n] != null ? String(fx.sub[n]).replace(".", ",") : ""; }); }
  const lockLbl = `<span class="lock">🔒 fixé par ${AG}</span>`;
  const objIn = (attrs, val) => fx ? `<input ${attrs} value="${esc(val)}" readonly class="locked" tabindex="-1">` : `<input ${attrs} ${money2("o")} value="${esc(val)}">`;
  const caBlock = !client ? "" : client.sous.length
    ? `<div class="muted">Saisissez le CA de chaque marque.</div>` + client.sous.map(n => `<div class="row"><label>CA ${esc(n)} (${sym})<input data-sub="${esc(n)}" data-sf="ca" ${money2("ca")} value="${esc(m.sub?.[n]?.ca ?? "")}"></label>
        <label>Objectif ${esc(n)} ${fx ? lockLbl : `<span class="hint">facultatif</span>`}${objIn(fx ? "" : `data-sub="${esc(n)}" data-sf="objectif"`, m.sub?.[n]?.objectif ?? "")}</label></div>`).join("")
    : `<div class="row"><label>CA réalisé (${sym})<input data-m="0" data-f="ca" ${money2("ca")} value="${esc(m.ca)}"></label>
        <label>Objectif du jour (${sym}) ${fx ? lockLbl : `<span class="hint">facultatif</span>`}${objIn(fx ? "" : `data-m="0" data-f="objectif"`, m.objectif || "")}</label></div>`;
  $("#view").innerHTML = `
  ${installHint()}
  <div id="draft-st" class="draft-st${S.stWarn ? " warn" : ""}">${esc(S.st || "")}</div>
  <div class="btns"><button class="ghost" id="to-home">‹ Accueil</button></div>
  <div class="card accent">
    <h2>Journée d'animation</h2>
    <div><div class="muted" style="margin-bottom:6px">Zone</div><div class="chips" id="zone">${L.zones.map(z => `<button type="button" class="chip" aria-pressed="${d.zone===z}" data-v="${z}">${z}</button>`).join("")}</div></div>
    ${d.zone ? `<label>Point de vente
      <select data-k="magasin_id" id="mag"><option value="">Choisir le magasin…</option>${mags.map(x => `<option value="${x.id}" ${String(d.magasin_id)===String(x.id)?"selected":""}>${esc(x.nom)} · ${esc(x.ville)}</option>`).join("")}
      <option value="autre" ${d.magasin_id==="autre"?"selected":""}>Autre point de vente (préciser)</option></select></label>
      ${d.magasin_id === "autre" ? `<div class="row"><label>Nom du point de vente<input data-k="magasin_autre" value="${esc(d.magasin_autre)}"></label><label>Ville<input data-k="ville_autre" value="${esc(d.ville_autre)}"></label></div>` : ""}` : `<div class="muted">Choisissez d'abord votre zone.</div>`}
    <label>Date de l'animation<input type="date" data-k="date" value="${esc(d.date)}" max="${today()}"></label>
  </div>

  <div class="card">
    <h2>Votre selfie</h2>
    <div class="muted">Une photo de vous sur le stand. Obligatoire.</div>
    <div class="photos">${selfie ? `<div class="thumbs">${del(selfie)}</div>` : `<div class="shoots">${shoot("animatrice", "Prendre mon selfie", "user")}${gallery("animatrice")}</div>`}</div>
  </div>

  <div class="card"><div class="brand">
    <h2>Marque animée</h2>
    <div class="clients" id="clients">${L.marques.map(x => `<button type="button" class="client" aria-pressed="${String(m.marque_id)===String(x.id)}" data-v="${x.id}"><b>${esc(x.nom)}</b>${x.detail ? `<span>(${esc(x.detail)})</span>` : ""}</button>`).join("")}</div>
    ${caBlock}
    ${client ? `
    <label>Réactions des clientes sur les nouveautés<textarea data-m="0" data-f="reactions" placeholder="Accueil des nouveautés, retours sur les produits, les prix…">${esc(m.reactions)}</textarea></label>
    <label>Ruptures de produits<textarea data-m="0" data-f="ruptures" placeholder="Références manquantes, depuis quand">${esc(m.ruptures)}</textarea></label>
    <label>Testeurs manquants<textarea data-m="0" data-f="testeurs" placeholder="Références sans testeur">${esc(m.testeurs)}</textarea></label>
    <div class="photos"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><div class="muted">Photos du rayon <span class="hint">· au moins une, jusqu'à ${RAYON} · aucune cliente ni personne reconnaissable</span></div><span class="pcount${rayFull ? " full" : ""}">${nRayon} / ${RAYON}</span></div>
      ${nRayon ? `<div class="thumbs">${photosOf("m0").map(del).join("")}</div>` : ""}
      ${rayFull ? `<div class="hint">Supprimez une photo pour en mettre une autre.</div>` : `<div class="shoots">${shoot("m0", "Photographier le rayon", "environment")}${gallery("m0", false, true)}</div>`}</div>` : `<div class="hint">Choisissez la marque pour saisir le CA.</div>`}
  </div></div>

  <div class="card">
    <h2>Le point de vente</h2>
    <div><div class="muted" style="margin-bottom:6px">Fréquentation</div><div class="chips" id="freq">${L.frequentation.map(f => `<button type="button" class="chip" aria-pressed="${d.frequentation===f}" data-v="${f}">${f}</button>`).join("")}</div></div>
    <div><div class="muted" style="margin-bottom:6px">Concurrence présente (animation, stand ou démonstration)</div><div class="chips multi" id="conc">${L.concurrence.map(c => `<button type="button" class="chip" aria-pressed="${d.concurrence.includes(c)}" data-v="${esc(c)}">${esc(c)}</button>`).join("")}</div></div>
    <label>Autre marque concurrente (préciser)<input data-k="concurrence_autre" value="${esc(d.concurrence_autre)}"></label>
    <label>Offres en cours sur le point de vente<textarea data-k="offres">${esc(d.offres)}</textarea></label>
    <label>Soucis avec le personnel ou la direction<textarea data-k="staff">${esc(d.staff)}</textarea></label>
    <label>Faits marquants utiles à l'activité du point de vente<textarea data-k="faits">${esc(d.faits)}</textarea></label>
  </div>
  <div class="drop-zone"><button class="ghost danger" id="drop">Abandonner ce brouillon</button><div class="hint">Efface tout ce qui a été saisi et les photos, pour repartir de zéro.</div></div>
  <div class="sticky-submit"><button class="primary" id="submit" style="width:100%">Envoyer le compte rendu</button></div>`;
  bind();
}

function bind(){
  const d = S.d, m = d.marques[0];
  bindInstall();
  $("#to-home").onclick = goHome;
  document.querySelectorAll("[data-k]").forEach(el => el.addEventListener(el.tagName === "SELECT" ? "change" : "input", () => {
    d[el.dataset.k] = el.value; saveDraft(); if (el.id === "mag" || el.dataset.k === "date") render();
  }));
  document.querySelectorAll("[data-m]").forEach(el => el.addEventListener("input", () => { m[el.dataset.f] = el.value; saveDraft(); }));
  document.querySelectorAll("[data-sub]").forEach(el => el.addEventListener("input", () => { m.sub = m.sub || {}; (m.sub[el.dataset.sub] = m.sub[el.dataset.sub] || {})[el.dataset.sf] = el.value; saveDraft(); }));
  $("#clients").onclick = e => { const b = e.target.closest(".client"); if (!b) return; m.marque_id = b.dataset.v; saveDraft(); render(); };
  $("#zone").onclick = e => { const b = e.target.closest(".chip"); if (!b) return; if (d.zone !== b.dataset.v) { d.zone = b.dataset.v; d.magasin_id = ""; } saveDraft(); render(); };
  $("#freq").onclick = e => { const b = e.target.closest(".chip"); if (!b) return; d.frequentation = b.dataset.v; saveDraft(); document.querySelectorAll("#freq .chip").forEach(c => c.setAttribute("aria-pressed", c === b)); };
  $("#conc").onclick = e => { const b = e.target.closest(".chip"); if (!b) return; const v = b.dataset.v;
    d.concurrence = d.concurrence.includes(v) ? d.concurrence.filter(x => x !== v) : d.concurrence.concat(v); b.setAttribute("aria-pressed", d.concurrence.includes(v)); saveDraft(); };
  document.querySelectorAll("[data-del]").forEach(b => b.onclick = async () => { try { S.photos = (await api("delete_photo", {id:+b.dataset.del, token:d.token})).photos; render(); } catch(e) { toast(e.message); } });
  document.querySelectorAll("input[type=file]").forEach(inp => inp.onchange = async () => {
    let files = [...inp.files]; inp.value = ""; if (!files.length) return;
    const slot = inp.dataset.slot;
    const room = slot === "animatrice" ? 1 : 4 - S.photos.filter(p => p.slot === slot).length - S.uploading;
    const cut = files.length > room; files = files.slice(0, Math.max(0, room));
    if (!files.length) return toast(slot === "animatrice" ? "Un seul selfie." : "4 photos du rayon maximum.");
    S.uploading += files.length; updateSubmit(); toast((cut ? "4 photos du rayon maximum : " : "") + "envoi de " + files.length + " photo" + (files.length > 1 ? "s" : "") + "…");
    for (const f of files) {
      try { const fd = new FormData(); fd.append("photo", await shrink(f), "photo.jpg"); fd.append("token", d.token); fd.append("slot", slot);
        S.photos = (await api("upload_photo", fd)).photos; }
      catch(e) { toast(e.message); }
      S.uploading--;
    }
    render();
  });
  $("#submit").onclick = submit; updateSubmit();
  $("#drop").onclick = () => confirmSheet("Confirmez-vous la suppression du brouillon ?", "La saisie et les photos de ce brouillon seront effacées.", async () => {
    try { await api("drop_draft", {token:d.token}); } catch(e) { return toast(e.message); }
    try { localStorage.removeItem(KEY()); } catch(e) {}
    clearTimeout(saveDraft.t); S.d = blank(); S.photos = []; setSt(""); toast("Brouillon supprimé"); goHome();
  });
}
/** Fenêtre de confirmation Oui / Non. */
function confirmSheet(title, text, onYes){
  document.getElementById("amy-confirm")?.remove();
  const d = document.createElement("div"); d.id = "amy-confirm";
  d.style.cssText = "position:fixed;inset:0;z-index:99;background:rgba(26,21,18,.55);display:flex;align-items:center;justify-content:center;padding:16px";
  d.innerHTML = `<div style="background:#fff;border-radius:18px;max-width:380px;width:100%;padding:22px 20px;text-align:center">
    <div style="font-size:18px;font-weight:600;margin-bottom:6px">${title}</div><div class="muted" style="margin-bottom:16px">${text}</div>
    <div style="display:flex;gap:10px"><button type="button" class="ghost" data-no style="flex:1">Non</button><button type="button" class="primary" data-yes style="flex:1;background:var(--bad);border-color:var(--bad)">Oui, supprimer</button></div></div>`;
  d.onclick = e => { if (e.target === d || e.target.closest("[data-no]")) d.remove(); else if (e.target.closest("[data-yes]")) { d.remove(); onYes(); } };
  document.body.appendChild(d);
}
function updateSubmit(){ const b = $("#submit"); if (!b) return; b.disabled = S.uploading > 0; b.textContent = S.uploading > 0 ? "Photos en cours d'envoi…" : "Envoyer le compte rendu"; }

async function submit(){
  const d = S.d, m = d.marques[0], client = S.L.marques.find(x => String(x.id) === String(m.marque_id));
  const caMiss = client && (client.sous.length ? client.sous.some(n => (m.sub?.[n]?.ca ?? "") === "") : m.ca === "");
  const miss = !d.zone ? "Choisissez votre zone." : !d.magasin_id ? "Choisissez le point de vente." : (d.magasin_id === "autre" && (!d.magasin_autre || !d.ville_autre)) ? "Indiquez le nom et la ville du point de vente." :
    !d.date ? "Indiquez la date." : !client ? "Choisissez la marque animée." : caMiss ? (client.sous.length ? "Indiquez le CA de " + client.sous.join(" et de ") + "." : "Indiquez le CA réalisé.") : !d.frequentation ? "Indiquez la fréquentation." :
    !S.photos.some(p => p.slot === "animatrice") ? "Ajoutez votre selfie sur le point de vente." : !S.photos.some(p => p.slot === "m0") ? "Ajoutez au moins une photo du rayon." : "";
  if (miss) return toast(miss);
  // CA : deux décimales au plus ; au-delà de 9 999 €, on fait confirmer pour éviter une faute de frappe.
  const cas = client.sous.length ? client.sous.map(n => [n, m.sub?.[n]?.ca ?? "", `[data-sub="${n}"][data-sf="ca"]`]) : [[client.nom, m.ca, `[data-m="0"][data-f="ca"]`]];
  const net = x => String(x ?? "").replace(/[\s€\u00a0]/g, "").replace(",", ".");
  const faux = cas.find(([, x]) => net(x) !== "" && !/^\d+(\.\d{1,2})?$/.test(net(x)));
  if (faux) { toast("CA mal écrit. Exemples : 1250 ou 1250,50."); document.querySelector(faux[2])?.focus(); return; }
  const gros = cas.filter(([, x]) => +net(x) > CA_ALERTE);
  if (gros.length && !(await askSheet("Montant élevé", gros.map(([n, x]) => `<b>${euros(+net(x))}</b> de CA pour ${esc(n)}`).join("<br>") + "<br><br>Est-ce bien correct ?", "Oui, c'est correct", "Corriger"))) {
    const el = document.querySelector(gros[0][2]); if (el) { el.focus(); el.scrollIntoView({block:"center"}); } return;
  }
  $("#submit").disabled = true; $("#submit").textContent = "Envoi…";
  try {
    await api("submit", Object.assign({}, d, {marques:[m]}));
    try { localStorage.removeItem(KEY()); } catch(e) {}
    clearTimeout(saveDraft.t); S.d = blank(); S.photos = []; setSt("");
    $("#view").innerHTML = `<div class="card accent done"><div class="big">Merci ${esc(S.me.prenom)} !</div><div class="muted">Votre compte rendu est bien enregistré.</div>
      ${isStandalone() ? "" : `<div class="install-after"><b>Pour la prochaine fois, installez l'appli sur votre téléphone</b><span>Vous la retrouverez d'un clic, avec votre historique.</span><a class="btn primary" href="installer.php" data-install>Installer l'appli</a></div>`}
      <div class="btns" style="width:100%"><button class="primary" id="again">Retour à l'accueil</button><button id="mine2">Mes comptes rendus</button></div></div>`;
    $("#again").onclick = goHome;
    $("#mine2").onclick = openMine;
    scrollTo(0, 0);
  } catch(e) { toast(e.message); $("#submit").disabled = false; $("#submit").textContent = "Envoyer le compte rendu"; }
}

/* ---------- Formations ---------- */
async function openFormations(){ S.view = "formations"; try { S.forma = (await api("formations")).formations; } catch(e) { toast(e.message); S.forma = []; } render(); scrollTo(0, 0); }
const FICON = {pdf:"PDF", video:"Vidéo", image:"Image", lien:"Lien", fichier:"Fichier"};
const size = n => n ? (n > 1048576 ? (n / 1048576).toFixed(1).replace(".", ",") + " Mo" : Math.max(1, Math.round(n / 1024)) + " Ko") : "";
function vFormations(){
  const F = S.forma || [];
  // Un onglet par marque (celles qui proposent des supports), plus « Général » pour les supports communs.
  const tabs = [];
  if (F.some(f => !f.marque_id)) tabs.push({k:"0", nom:"Général"});
  S.L.marques.forEach(m => { if (F.some(f => +f.marque_id === +m.id)) tabs.push({k:String(m.id), nom:m.nom}); });
  const cur = tabs.find(t => t.k === S.formTab) ? S.formTab : (tabs[0] || {}).k;
  const list = F.filter(f => String(f.marque_id || 0) === cur);
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Accueil</button></div>
   <div class="card"><h2>Formations</h2><div class="muted">Fiches, vidéos et argumentaires mis à disposition par ${AGN}. Choisissez la marque.</div></div>
   ${tabs.length ? `<div class="ftabs" id="ftabs">${tabs.map(t => `<button type="button" class="ftab" aria-current="${t.k === cur}" data-k="${t.k}">${esc(t.nom)}<span>${F.filter(f => String(f.marque_id || 0) === t.k).length}</span></button>`).join("")}</div>
   ${list.map(f => `
     <div class="fcard"><div class="fic fic-${f.type}">${FICON[f.type] || "Fichier"}</div><div class="fbody"><b>${esc(f.titre)}</b>${f.description ? `<div class="muted">${esc(f.description)}</div>` : ""}
       ${f.type === "video" ? `<video controls preload="none" playsinline src="formation.php?id=${f.id}"></video>` : ""}
       ${f.type === "image" ? `<a href="formation.php?id=${f.id}" target="_blank" rel="noopener"><img class="fimg" src="formation.php?id=${f.id}" alt="" loading="lazy"></a>` : ""}
       <div class="fact">${f.type === "lien" ? `<a class="btn" href="formation.php?id=${f.id}" target="_blank" rel="noopener">Ouvrir le lien</a>` : `<a class="btn" href="formation.php?id=${f.id}" target="_blank" rel="noopener">${f.type === "video" ? "Plein écran" : "Ouvrir"}</a><a class="btn ghost" href="formation.php?id=${f.id}&dl=1">Télécharger${f.taille ? " · " + size(+f.taille) : ""}</a>`}</div></div></div>`).join("")}`
   : `<div class="card"><div class="empty">Aucun support pour l'instant. Ils apparaîtront ici dès que ${AGN} en ajoutera.</div></div>`}`;
  $("#back").onclick = goHome;
  if ($("#ftabs")) $("#ftabs").onclick = e => { const b = e.target.closest(".ftab"); if (!b) return; S.formTab = b.dataset.k; vFormations(); };
}

/* ---------- Ma fiche ---------- */
const PIECES = {cni:"Carte d'identité", passeport:"Passeport", titre:"Titre de séjour / autorisation de travail", vitale:"Carte Vitale / attestation de sécurité sociale"};
const PI_BLOCS = [["Carte d'identité ou passeport", ["cni", "passeport"], "Carte d'identité : recto et verso. Passeport : la page avec la photo."],
  ["Carte Vitale", ["vitale"], "Carte Vitale ou attestation de droits (Ameli)."], ["Titre de séjour", ["titre"], "Seulement si vous n'êtes pas ressortissant(e) de l'Union européenne : titre de séjour ou autorisation de travail, recto et verso."]];
async function openFiche(){ S.view = "fiche"; try { S.fiche = await api("ma_fiche"); } catch(e) { return toast(e.message); } render(); scrollTo(0, 0); }
function vFiche(){
  const F = S.fiche, d = (F.data && !Array.isArray(F.data)) ? F.data : {}, L = F.listes;
  const chips = (k, list) => `<div class="chips multi" data-multi="${k}">${list.map(v => `<button type="button" class="chip" aria-pressed="${(d[k] || []).includes(v)}" data-v="${esc(v)}">${esc(v)}</button>`).join("")}</div>`;
  const one = (k, list) => `<div class="chips" data-one="${k}">${list.map(v => `<button type="button" class="chip" aria-pressed="${d[k] === v}" data-v="${esc(v)}">${esc(v)}</button>`).join("")}</div>`;
  const yes = (k, lab) => `<label class="ck"><input type="checkbox" data-b="${k}" ${d[k] ? "checked" : ""}><span>${lab}</span></label>`;
  const inp = (k, lab, ph = "", type = "text") => `<label>${lab}<input data-t="${k}" type="${type}" value="${esc(d[k] ?? "")}" placeholder="${esc(ph)}"></label>`;
  const sel = (k, lab, list) => `<label>${lab}<select data-t="${k}"><option value="">—</option>${list.map(v => `<option ${d[k] === v ? "selected" : ""}>${v}</option>`).join("")}</select></label>`;
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Accueil</button></div>
   <div class="card accent"><h2>Ma fiche</h2><div class="muted">Ces informations aident ${AGN} à vous proposer les missions qui vous correspondent. Seule l'équipe ${AG} les voit.</div>
     <div class="fiche-head"><label class="avatar">${F.photo ? `<img src="${F.photo}" alt="">` : `<span>Ma photo<br><b>obligatoire</b></span>`}<input type="file" accept="image/*" id="fi-photo" hidden></label>
       <div><b>${esc(S.me.prenom)} ${esc(String(S.me.nom || "").toUpperCase())}</b><div class="muted">${esc(S.me.zone)} · ${esc(S.me.email || "")}</div><div class="hint">Touchez la photo pour la changer.</div></div></div></div>
   <div class="card"><h2>Coordonnées</h2>${inp("adresse", "Adresse")}<div class="row">${inp("code_postal", "Code postal", "", "text")}${inp("ville", "Ville")}</div></div>
   <div class="card"><h2>Pour votre contrat de travail</h2><div class="muted">Ces informations figurent sur vos contrats. Elles ne sont vues que par ${AGN}.</div>
     <div class="row"><label>Civilité<select data-t="civilite"><option value="">—</option>${["Mme", "M."].map(v => `<option ${d.civilite === v ? "selected" : ""}>${v}</option>`).join("")}</select></label>${natField(d.nationalite || "", "data-nat")}</div>
     <div class="row">${inp("naissance_date", "Date de naissance", "", "date")}${inp("naissance_lieu", "Lieu de naissance")}</div>
     ${inp("nom_naissance", "Nom de naissance (si différent)")}
     ${secuField(d.secu || "", "data-secu")}</div>
   <div class="card"><h2>Pour votre salaire</h2><div class="muted">Coordonnées bancaires pour le virement de votre salaire. Elles sont chiffrées.</div>
     <div class="row">${inp("iban", "IBAN", "FR76 …")}${inp("bic", "BIC")}</div></div>
   <button class="card accent" id="fi-docs" style="text-align:left;cursor:pointer;font:inherit;color:inherit;width:100%"><b>Mes documents administratifs</b><div class="muted">Pièce d'identité, IBAN, Carte Vitale, photo pour badge… se déposent dans « Mes documents ».</div></button>
   <div class="card"><h2>Statut</h2>${one("statut", L.statuts)}<div data-siret ${[L.ae, "Auto-entrepreneuse"].includes(d.statut) ? "" : "hidden"}>${inp("siret", "Numéro SIRET (obligatoire)", "14 chiffres")}<div class="hint">Vos factures se déposent dans « Mes documents ».</div></div></div>
   <div class="card"><h2>Mobilité</h2>${yes("permis", "J'ai le permis B")}${yes("vehicule", "J'ai un véhicule")}
     <div class="muted" style="margin-top:8px">Où pouvez-vous travailler ? Choisissez des départements entiers et/ou des communes dans la liste officielle : ${AG} vous retrouve ainsi sans erreur.</div>
     <label>Départements<select id="fi-dep"><option value="">Ajouter un département…</option>${Object.entries(L.departements || {}).map(([k, v]) => `<option value="${k}">${k} · ${esc(v)}</option>`).join("")}</select></label>
     <div class="chips" id="fi-deps">${(d.mob_deps || []).map(k => `<button type="button" class="chip" aria-pressed="true" data-rmdep="${k}">${k} · ${esc((L.departements || {})[k] || "")} ✕</button>`).join("")}</div>
     <label>Communes<input id="fi-com" placeholder="Tapez le début du nom, puis choisissez" autocomplete="off"></label><div id="fi-comsug" class="chips"></div>
     <div class="chips" id="fi-coms">${(d.mob_communes || []).map((c, i) => `<button type="button" class="chip" aria-pressed="true" data-rmcom="${i}">${esc(c.nom)} (${esc(c.dep)}) ✕</button>`).join("")}</div>
     <label>Précisions <span class="hint">facultatif</span><textarea data-t="mobilite" rows="2" placeholder="Ex. pas avant 9 h, pas de déplacement le dimanche">${esc(d.mobilite || "")}</textarea></label></div>
   <div class="card"><h2>Disponibilités</h2><div class="muted">Jours où vous êtes habituellement disponible</div>${chips("jours", L.jours)}${yes("nocturnes", "Disponible pour les nocturnes et événements en soirée")}</div>
   <div class="card"><h2>Métiers qui m'intéressent</h2>${chips("metiers", L.metiers || [])}</div>
   <div class="card"><h2>Langues parlées</h2>${chips("langues", L.langues)}${inp("langues_autres", "Autres langues")}</div>
   <div class="card"><h2>Compétences</h2>${chips("competences", L.competences)}</div>
   <div class="card"><h2>Expérience en événementiel</h2>${chips("accueil", L.accueil || [])}</div>
   <div class="card"><h2>Formations et certificats</h2>${chips("certifs", L.certifs || [])}${sel("etudes", "Niveau d'études", L.etudes || [])}</div>
   <div class="card"><h2>Expérience</h2><label>Années d'expérience (animation, accueil, événementiel)<input data-t="experience" type="number" inputmode="numeric" min="0" max="60" value="${esc(d.experience ?? "")}"></label>
     <label>Expériences marquantes <span class="hint">types de missions, événements, lieux</span><textarea data-t="marques" rows="3" placeholder="Ex. accueil d'un salon professionnel de 3 jours, démonstrations en grande surface, soirée de lancement">${esc(d.marques || "")}</textarea></label>
     <label>Quelques mots sur vous <span class="hint">facultatif</span><textarea data-t="presentation" rows="3">${esc(d.presentation || "")}</textarea></label></div>
   <div class="card"><h2>Tenue</h2><div class="muted">Pour prévoir les tenues fournies par le client.</div><div class="row3">${sel("taille_haut", "Haut", L.tailles)}${sel("taille_bas", "Bas", L.tailles)}${inp("pointure", "Pointure", "Ex. 38")}</div>${inp("stature", "Taille (cm)", "Ex. 170", "number")}</div>
   <div class="card"><h2>Mon book</h2><div class="muted">Jusqu'à ${F.book_max || 6} photos récentes (en pied, en tenue de travail) : elles aident ${AG} à vous proposer pour l'accueil et les événements.</div>
     <div class="ph-grid">${(F.book || []).map(b => `<div class="ph-it"><img src="${b.url}" alt=""><button type="button" class="ghost sm" data-bkdel="${b.f}">Retirer</button></div>`).join("")}</div>
     ${(F.book || []).length < (F.book_max || 6) ? `<label class="btn" style="text-align:center">Ajouter des photos<input type="file" accept="image/*" id="fi-book" multiple hidden></label>` : ""}</div>
   <div class="card"><h2>CV</h2>${F.cv ? `<div><a href="fiche_fichier.php" target="_blank" rel="noopener">${esc(F.cv_nom || "Mon CV")}</a></div>` : `<div class="muted">Aucun CV pour l'instant.</div>`}
     <label class="btn" style="text-align:center">${F.cv ? "Remplacer mon CV" : "Ajouter mon CV"} (PDF)<input type="file" accept="application/pdf,.pdf" id="fi-cv" hidden></label></div>
   <div class="sticky-submit"><button class="primary" id="fi-save" style="width:100%">Enregistrer ma fiche</button></div><div style="height:80px"></div>`;
  const D = F.data = d;
  $("#back").onclick = goHome;
  document.querySelectorAll("[data-t]").forEach(el => el.addEventListener(el.tagName === "SELECT" ? "change" : "input", () => { D[el.dataset.t] = el.value; }));
  document.querySelectorAll("[data-b]").forEach(el => el.onchange = () => { D[el.dataset.b] = el.checked; });
  document.querySelectorAll("[data-multi]").forEach(box => box.onclick = e => { const b = e.target.closest(".chip"); if (!b) return; const k = box.dataset.multi, v = b.dataset.v; D[k] = D[k] || [];
    D[k] = D[k].includes(v) ? D[k].filter(x => x !== v) : D[k].concat(v); b.setAttribute("aria-pressed", D[k].includes(v)); });
  document.querySelectorAll("[data-one]").forEach(box => box.onclick = e => { const b = e.target.closest(".chip"); if (!b) return; D[box.dataset.one] = b.dataset.v;
    box.querySelectorAll(".chip").forEach(c => c.setAttribute("aria-pressed", c === b)); const s = document.querySelector("[data-siret]"); if (s) s.hidden = D.statut !== L.ae; });
  // Mobilité : départements et communes de la liste officielle (geo.api.gouv.fr).
  $("#fi-dep").onchange = e => { const k = e.target.value; if (!k) return; D.mob_deps = [...new Set((D.mob_deps || []).concat(k))]; vFiche(); };
  document.querySelectorAll("[data-rmdep]").forEach(b => b.onclick = () => { D.mob_deps = (D.mob_deps || []).filter(x => x !== b.dataset.rmdep); vFiche(); });
  document.querySelectorAll("[data-rmcom]").forEach(b => b.onclick = () => { D.mob_communes = (D.mob_communes || []).filter((x, i) => i !== +b.dataset.rmcom); vFiche(); });
  let tq; $("#fi-com").oninput = e => { clearTimeout(tq); const q = e.target.value.trim(); if (q.length < 2) { $("#fi-comsug").innerHTML = ""; return; }
    tq = setTimeout(async () => { try { const r = await fetch("https://geo.api.gouv.fr/communes?nom=" + encodeURIComponent(q) + "&fields=nom,codesPostaux,codeDepartement&boost=population&limit=8"); const L2 = (await r.json()).filter(c => !/^97/.test(c.codeDepartement));
      $("#fi-comsug").innerHTML = L2.length ? L2.map((c, i) => `<button type="button" class="chip" data-addcom="${i}">${esc(c.nom)} (${esc(c.codeDepartement)})</button>`).join("") : `<span class="hint">Aucune commune trouvée : vérifiez l'orthographe.</span>`;
      document.querySelectorAll("[data-addcom]").forEach(b => b.onclick = () => { const c = L2[+b.dataset.addcom]; D.mob_communes = (D.mob_communes || []).filter(x => x.nom !== c.nom || x.dep !== c.codeDepartement).concat({nom:c.nom, cp:(c.codesPostaux || [""])[0], dep:c.codeDepartement}); vFiche(); }); }
      catch(x) { $("#fi-comsug").innerHTML = `<span class="hint">Recherche indisponible : choisissez plutôt un département.</span>`; } }, 250); };
  document.querySelectorAll("[data-bkdel]").forEach(b => b.onclick = async () => { try { F.book = (await api("fiche_book_del", {f:b.dataset.bkdel})).book; vFiche(); } catch(e) { toast(e.message); } });
  if ($("#fi-book")) $("#fi-book").onchange = async e => { for (const f of [...e.target.files].slice(0, (F.book_max || 6) - (F.book || []).length)) { try { const fd = new FormData(); fd.append("photo", await shrink(f), "photo.jpg"); F.book = (await api("fiche_book", fd)).book; } catch(x) { toast(x.message); } } vFiche(); };
  $("#fi-photo").onchange = async () => { const f = $("#fi-photo").files[0]; if (!f) return; const fd = new FormData(); fd.append("photo", await shrink(f), "photo.jpg");
    try { F.photo = (await api("fiche_photo", fd)).photo; vFiche(); toast("Photo enregistrée"); } catch(e) { toast(e.message); } };
  $("#fi-cv").onchange = async () => { const f = $("#fi-cv").files[0]; if (!f) return; const fd = new FormData(); fd.append("cv", f);
    try { const r = await api("fiche_cv", fd); F.cv = true; F.cv_nom = r.cv_nom; vFiche(); toast("CV enregistré"); } catch(e) { toast(e.message); } };
  natBind("data-nat", v => D.nationalite = v);
  const reSecu = secuBind("data-secu", () => D.civilite, () => D.naissance_date, v => D.secu = v);
  document.querySelectorAll('[data-t="civilite"],[data-t="naissance_date"]').forEach(el => el.addEventListener("change", () => setTimeout(reSecu)));
  $("#fi-docs").onclick = () => openDocs("adm");
  $("#fi-save").onclick = async () => { const b = $("#fi-save"); b.disabled = true; try { await api("save_fiche", {data:D}); S.nbPlan = undefined; toast("Fiche enregistrée"); } catch(e) { toast(e.message); } b.disabled = false; };
}

/* ---------- Mes documents ---------- */
// Documents nécessaires à l'embauche : un bloc par document, rouge pointillé tant qu'il manque, vert quand il est déposé.
const DOC_BLOCS = [
  ["ident", "Pièce d'identité", ["cni", "passeport"], "Carte d'identité recto et verso, ou la page photo du passeport.", true, '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16c.6-1.6 1.8-2.4 3.2-2.4s2.6.8 3.2 2.4M14.5 10h4M14.5 13h3"/>'],
  ["rib", "IBAN / RIB", ["rib"], "Le RIB de votre banque, pour le virement de votre salaire.", true, '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/>'],
  ["vitale", "Carte Vitale / sécurité sociale", ["vitale"], "Carte Vitale ou attestation de droits.", true, '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M13 11v6M10 14h6"/>'],
  ["photo", "Photo d'identité", ["photo"], "Sur fond clair, pour les badges demandés par certains points de vente.", true, '<rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M7 18c.9-2.2 2.8-3.4 5-3.4s4.1 1.2 5 3.4"/>'],
  ["titre", "Titre de séjour", ["titre"], "Seulement si vous n'êtes pas ressortissant(e) de l'Union européenne.", false, '<path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>'],
];
const DOC_SAL = {bulletin:"Bulletin de salaire", ft:"Attestation France Travail", sal_autre:"Document"};
const DOC_CAT = {charte:("Charte " + AG), dress:"Dress code", reglement:"Règlement intérieur", mutuelle:"Mutuelle et prévoyance", autre:"Autres documents"};
const moisTxt = p => p ? ["janvier","février","mars","avril","mai","juin","juillet","août","septembre","octobre","novembre","décembre"][+p.slice(5, 7) - 1] + " " + p.slice(0, 4) : "";
function docsCompte(){ const P = (S.docs || {}).pieces || [];
  S.docsNew = P.filter(p => DOC_SAL[p.type] && !p.vu_le).length;
  S.docsManque = DOC_BLOCS.filter(b => b[4] && !P.some(p => b[2].includes(p.type))).length; }
async function openDocs(tab){ S.view = "docs"; S.docTab = tab || S.docTab || "adm"; try { S.docs = await api("mes_documents"); docsCompte(); } catch(e) { return toast(e.message); }
  if (!S.ctrs) try { S.ctrs = (await api("mes_contrats")).contrats; S.rels = (await api("mes_releves")).releves; } catch(e) {}
  render(); scrollTo(0, 0); }
function vDocs(){
  const D = S.docs, P = D.pieces, tab = S.docTab;
  const ico = path => `<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">${path}</svg>`;
  const fichier = p => `<div class="dc-f"><span>${esc(p.titre || p.nom)}</span><a href="piece.php?id=${p.id}" target="_blank" rel="noopener">Consulter</a><button type="button" data-ddel="${p.id}">Supprimer</button></div>`
    + (["cni", "passeport", "titre"].includes(p.type) ? `<label class="dc-exp">Valable jusqu'au <input type="date" data-dexp="${p.id}" value="${esc(p.expire_le || "")}"><span class="hint">un rappel vous est envoyé un mois avant</span></label>` : "");
  const tabs = [["adm", "Administratifs" + (S.docsManque ? ` <span class="pill">${S.docsManque}</span>` : "")], ["sal", "Salariaux" + (S.docsNew ? ` <span class="pill">${S.docsNew}</span>` : "")], ["reg", "Règlements"], ["sig", "Signés"]];
  let body = "";
  if (tab === "adm") {
    body = `<div class="card"><h2>Documents nécessaires à l'embauche</h2><div class="muted">Photo ou PDF, 10 Mo maximum. Vos documents sont chiffrés sur le serveur : seule la direction peut les ouvrir.</div>
      <div class="dc-grid">${DOC_BLOCS.map(([k, t, types, aide, oblig, svg]) => { const L = P.filter(p => types.includes(p.type)), ok = L.length > 0;
        return `<div class="dc ${ok ? "ok" : oblig ? "ko" : "opt"}"><div class="dc-i">${ico(svg)}</div><div class="dc-t">${ok ? "✓" : oblig ? "✕" : "○"} ${esc(t)}</div><div class="hint">${esc(aide)}</div>
          ${L.map(fichier).join("")}
          ${types.length > 1 ? `<select data-dtyp="${k}">${types.map(x => `<option value="${x}">${x === "cni" ? "Carte d'identité" : "Passeport"}</option>`).join("")}</select>` : ""}
          <label class="dc-add">${ok ? "Ajouter une page" : "Déposer"}<input type="file" accept="image/*,application/pdf" data-dfile="${k}" data-dtype="${types[0]}" hidden></label></div>`; }).join("")}</div></div>
      <div class="card"><h2>Documents supplémentaires</h2><div class="muted">Diplôme, attestation, carte professionnelle… tout autre document utile.</div>
        ${P.filter(p => p.type === "autre").map(fichier).join("")}
        <label class="dc-drop"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M7 18a5 5 0 1 1 1-9.9A6 6 0 0 1 19 10a4 4 0 0 1-1 7.9"/><path d="M12 12v8M9 15l3-3 3 3"/></svg><span>Déposer un document</span><input type="file" accept="image/*,application/pdf" data-dfile="autre" data-dtype="autre" hidden></label></div>`;
  } else if (tab === "sal") {
    const L = P.filter(p => DOC_SAL[p.type]);
    body = `<div class="card"><h2>Documents salariaux</h2><div class="muted">Vos bulletins de salaire et attestations France Travail, déposés par ${AG}. Gardez-les : vous pouvez les télécharger à tout moment.</div>
      ${L.length ? `<div class="dc-list">${L.map(p => `<div class="dc-row"><div><b>${esc(DOC_SAL[p.type])}${p.periode ? " · " + esc(moisTxt(p.periode)) : ""}</b>${!p.vu_le ? ` <span class="pill">nouveau</span>` : ""}<div class="hint">${esc(p.titre || p.nom)}</div></div>
        <div class="dc-act"><a class="btn ghost sm" href="piece.php?id=${p.id}" target="_blank" rel="noopener" data-dvu>Consulter</a><a class="btn ghost sm" href="piece.php?id=${p.id}&dl=1">Télécharger</a></div></div>`).join("")}</div>`
        : `<div class="empty">Aucun document pour l'instant. Vos bulletins apparaîtront ici dès qu'ils seront déposés.</div>`}</div>`;
  } else if (tab === "reg") {
    const G = {}; D.agence.forEach(d => (G[d.categorie] = G[d.categorie] || []).push(d));
    body = `<div class="card"><h2>Règlements et conditions</h2><div class="muted">Charte, dress code, règlement intérieur, mutuelle : à lire avant votre première mission.</div>
      ${D.agence.length ? Object.keys(DOC_CAT).filter(c => G[c]).map(c => `<h3 class="dc-h">${esc(DOC_CAT[c])}</h3><div class="dc-list">${G[c].map(d => `<div class="dc-row"><div><b>${esc(d.titre)}</b></div><div class="dc-act"><a class="btn ghost sm" href="piece.php?g=${d.id}" target="_blank" rel="noopener">Lire</a></div></div>`).join("")}</div>`).join("")
        : `<div class="empty">Aucun document pour l'instant.</div>`}</div>`;
  } else {
    const C = (S.ctrs || []).filter(c => c.statut === "signe"), R = (S.rels || []).filter(r => r.statut === "signe");
    body = `<div class="card"><h2>Documents signés électroniquement</h2><div class="muted">Vos contrats, avenants et relevés d'heures signés dans l'appli.</div>
      ${C.length + R.length ? `<div class="dc-list">${C.map(c => `<div class="dc-row"><div><b>${esc(c.titre || "Contrat")}</b><div class="hint">${esc(c.client || "")}</div></div><div class="dc-act"><a class="btn ghost sm" href="api.php?action=contrat_pdf_mien&id=${c.id}" target="_blank" rel="noopener">PDF</a></div></div>`).join("")}
        ${R.map(r => `<div class="dc-row"><div><b>Relevé d'heures</b><div class="hint">${esc(r.mois_txt || r.mois)}</div></div><div class="dc-act"><a class="btn ghost sm" href="api.php?action=releve_pdf_mien&mois=${r.mois}" target="_blank" rel="noopener">PDF</a></div></div>`).join("")}</div>`
        : `<div class="empty">Aucun document signé pour l'instant.</div>`}</div>`;
  }
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Accueil</button></div>
    <div class="card accent"><h2>Mes documents</h2><div class="dc-tabs">${tabs.map(([k, l]) => `<button data-dtab="${k}" aria-current="${tab === k}">${l}</button>`).join("")}</div></div>${body}`;
  $("#back").onclick = goHome;
  document.querySelectorAll("[data-dtab]").forEach(b => b.onclick = () => { S.docTab = b.dataset.dtab; vDocs(); });
  document.querySelectorAll("[data-dvu]").forEach(a => a.addEventListener("click", () => setTimeout(() => openDocs("sal"), 1500)));
  document.querySelectorAll("[data-dfile]").forEach(inp => inp.onchange = async () => { const f = inp.files[0]; if (!f) return; const sel = document.querySelector(`[data-dtyp="${inp.dataset.dfile}"]`);
    const fd = new FormData(); fd.append("type", sel ? sel.value : inp.dataset.dtype); fd.append("fichier", f.type.startsWith("image/") ? await shrink(f) : f, f.type.startsWith("image/") ? "document.jpg" : f.name);
    if (inp.dataset.dtype === "autre") fd.append("titre", f.name.replace(/\.[^.]+$/, ""));
    try { S.docs.pieces = (await api("piece_upload", fd)).pieces; docsCompte(); vDocs(); toast("Document enregistré"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-dexp]").forEach(i => i.onchange = async () => { try { S.docs.pieces = (await api("piece_expiration", {id:+i.dataset.dexp, date:i.value})).pieces; toast("Date enregistrée"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-ddel]").forEach(b => b.onclick = async () => { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer"; return; }
    try { S.docs.pieces = (await api("piece_delete", {id:+b.dataset.ddel})).pieces; docsCompte(); vDocs(); } catch(e) { toast(e.message); } });
}

/* ---------- Accueil ---------- */
function goHome(){ S.view = "home"; render(); scrollTo(0, 0); }
function goForm(){ S.view = "form"; render(); scrollTo(0, 0); }
// Rappel : animations qui commencent dans moins de 24 h (ou en cours), en tête de l'accueil.
function rappel24(){
  const now = new Date(), L = (S.plan || []).filter(m => { const d = new Date(m.date + "T" + (m.debut || "00:00")), f = new Date(m.date + "T" + (m.fin || "23:59"));
    return d - now <= 864e5 && f > now; });
  if (!L.length) return "";
  const quand = m => { const d = new Date(m.date + "T" + (m.debut || "00:00")), j = m.date === today() ? "Aujourd'hui" : "Demain";
    return d <= now ? "En cours" : j + " à " + esc(m.debut); };
  return L.map(m => `<button class="card rappel" data-rappel><div class="rp-k">⏰ Rappel · ${quand(m)}</div><b>${esc(m.magasin || "Point de vente à préciser")}</b>
    <div class="muted">${esc(pLong(m.date))}, ${esc(m.debut)} à ${esc(m.fin)}${m.client ? " · " + esc(m.client) : ""}${m.poste ? " · " + esc(m.poste) : ""}</div>
    <div class="hint">Pensez à votre tenue, à votre badge si besoin, et à envoyer votre compte rendu en fin de journée. Touchez pour voir le trajet.</div></button>`).join("");
}
/** Prise de poste : le jour de la mission, un bouton pour signaler son arrivée (heure et position comparées au point de vente). */
function posteCards(){
  const L = (S.plan || []).filter(m => m.poste_ouvert || (m.poste_pris && m.date === today()));
  return L.map(m => m.poste_pris ? `<div class="card pp-ok"><b>✓ Poste pris à ${esc(m.poste_pris.heure)}</b><div class="muted">${esc(m.magasin || "")} · début prévu ${esc(m.debut)}${m.poste_pris.ecart > 5 ? ` · ${m.poste_pris.ecart} min de retard signalées à ${AG}` : ""}</div></div>`
    : `<div class="card accent pp"><div class="rp-k">Prise de poste · ${esc(m.debut)} à ${esc(m.fin)}</div><b>${esc(m.magasin || "Point de vente")}</b>
      <div class="muted">Arrivé(e) sur place ? Validez votre prise de poste : l'heure et votre position sont envoyées à ${AG}, une seule fois, pour cette mission.</div>
      <div class="btns"><button class="primary" data-pp="${m.id}">Je prends mon poste</button></div></div>`).join("");
}
async function prendrePoste(b){
  const id = +b.dataset.pp; b.disabled = true; b.textContent = "Localisation…";
  const pos = await new Promise(ok => { if (!navigator.geolocation) return ok(null);
    navigator.geolocation.getCurrentPosition(p => ok(p.coords), () => ok(null), {enableHighAccuracy:true, timeout:12000, maximumAge:60000}); });
  try { const r = await api("prise_poste", {mission_id:id, lat:pos ? pos.latitude : null, lng:pos ? pos.longitude : null, acc:pos ? Math.round(pos.accuracy) : null});
    toast(r.code === "retard" ? "Prise de poste enregistrée (" + r.ecart + " min de retard)" : "Prise de poste enregistrée" + (pos ? "" : " (sans position : autorisez la localisation la prochaine fois)"));
    const m = (S.plan || []).find(x => +x.id === id); if (m) { m.poste_pris = {heure:r.heure, ecart:r.ecart}; m.poste_ouvert = false; } render(); }
  catch(e) { toast(e.message); b.disabled = false; b.textContent = "Je prends mon poste"; }
}
function vHome(){
  const d = S.d, started = d && (d.magasin_id || d.marques[0].marque_id || S.photos.length);
  const h = new Date().getHours(), hello = h < 18 ? "Bonjour" : "Bonsoir";
  const M = null;
  $("#view").innerHTML = `
   <div class="home-hero"><div class="hk">${AG}</div><div class="ht">${hello} ${esc(S.me.prenom)}</div>
     <div class="hs">Votre espace ${AGN} : vos missions, vos comptes rendus et vos formations, au même endroit.</div></div>
   ${PUSH_OK || estIOS() ? pushCarte() : ""}
   ${S.planVis ? posteCards() + rappel24() : ""}
   ${S.planVis && S.nbOf ? `<button class="card accent of-notif" id="t-ofn"><b>🔔 ${S.nbOf === 1 ? "Une nouvelle mission vous est proposée" : S.nbOf + " nouvelles missions vous sont proposées"}</b><div class="muted">Touchez ici pour voir les dates et postuler.</div></button>` : ""}
   ${S.planVis && S.manque && S.manque.length ? `<button class="card accent" id="t-manque" style="text-align:left;cursor:pointer;font:inherit;color:inherit"><b>Complétez votre fiche pour vos contrats de travail</b><div class="muted">Il manque : ${esc(S.manque.join(", "))}.</div></button>` : ""}
   <div class="tiles">
     ${(S.mcM || []).length ? (() => { const af = S.mcM.filter(x => !x.fait), auj = af.filter(x => x.date === new Date().toISOString().slice(0, 10)).length;
       return `<button class="tile${af.length ? " main" : ""}" id="t-mc"><span class="tt">Visite merchandising${af.length ? ` <span class="pill">${af.length} à faire</span>` : ""}</span><span class="td">${auj ? auj + " point" + (auj > 1 ? "s" : "") + " d'intervention aujourd'hui : " : ""}photos avant/après, relevés de prix et de présence, linéaire, promotions.</span></button>`; })() : ""}
     ${(S.evM || []).length ? `<button class="tile${S.evM.some(x => !x.fait) ? " main" : ""}" id="t-evt"><span class="tt">Rapport d'événement${S.evM.some(x => !x.fait) ? ` <span class="pill">${S.evM.filter(x => !x.fait).length} à faire</span>` : ""}</span><span class="td">Salon, soirée, accueil : affluence, contacts, déroulement et photos de l'événement.</span></button>` : ""}
     <button class="tile${M || (S.evM || []).some(x => !x.fait) ? "" : " main"}" id="t-cr"><span class="tt">${started ? "Reprendre mon compte rendu" : M ? "Autre animation" : "Nouveau compte rendu"}</span>
       <span class="td">${started ? "Votre brouillon est enregistré. Complétez-le et envoyez-le en fin de journée." : "Saisissez votre journée, même en plusieurs fois : tout est enregistré au fur et à mesure."}</span></button>
     ${S.planVis ? `<button class="tile${S.nextM ? " main" : ""}" id="t-plan"><span class="tt">Mon planning${S.nbPlan ? ` <span class="pill">${S.nbPlan} à venir</span>` : ""}</span><span class="td">${S.nextM ? "Prochaine : " + esc(pLong(S.nextM.date)) + ", " + esc(S.nextM.magasin || "point de vente à préciser") + ", " + esc(S.nextM.debut) + " à " + esc(S.nextM.fin) + "." : "Calendrier et récap de vos animations."}</span></button>
     ${(S.offres || []).length ? `<button class="tile${S.nbOf ? " main" : ""}" id="t-of"><span class="tt">Missions proposées${S.nbOf ? ` <span class="pill">${S.nbOf} nouvelle${S.nbOf > 1 ? "s" : ""}</span>` : ""}</span><span class="td">Des missions près de chez vous : postulez en un clic.</span></button>` : ""}
     <button class="tile${S.nbCt ? " main" : ""}" id="t-ct"><span class="tt">Mes contrats${S.nbCt ? ` <span class="pill">${S.nbCt} à signer</span>` : ""}</span><span class="td">Vos contrats de travail et avenants, à lire et signer ici.</span></button>
     <button class="tile" id="t-rel"><span class="tt">Mes relevés d'heures${S.nbRel ? ` <span class="pill">${S.nbRel} à signer</span>` : ""}</span><span class="td">Vos heures du mois, vérifiées par ${AG}, à signer ici.</span></button>` : ""}
     <button class="tile" id="t-mine"><span class="tt">Mes comptes rendus</span><span class="td">Revoyez vos journées en entier et recevez-les en PDF.</span></button>
     <button class="tile" id="t-form"><span class="tt">Formations</span><span class="td">Fiches produit, vidéos et argumentaires, marque par marque.</span></button>
     <button class="tile${S.docsNew ? " main" : ""}" id="t-docs"><span class="tt">Mes documents${S.docsNew ? ` <span class="pill">${S.docsNew} nouveau${S.docsNew > 1 ? "x" : ""}</span>` : S.docsManque ? ` <span class="pill">${S.docsManque} à fournir</span>` : ""}</span><span class="td">Pièces pour l'embauche, bulletins de salaire, attestations, charte et règlements.</span></button>
     ${S.planVis ? `<button class="tile" id="t-dispo"><span class="tt">Mes disponibilités</span><span class="td">Les jours où vous pouvez travailler : ${AG} vous propose les missions en priorité.</span></button>
     ${S.fae && S.fae.ae ? `<button class="tile" id="t-fae"><span class="tt">Mes factures</span><span class="td">Auto-entrepreneur : déposez vos factures et suivez leur paiement.</span></button>` : ""}
     <button class="tile" id="t-ndf"><span class="tt">Mes notes de frais</span><span class="td">Parking, carburant, péage : photo du ticket, remboursé avec la paye.</span></button>` : ""}
     <button class="tile" id="t-fiche"><span class="tt">Ma fiche</span><span class="td">Votre profil, vos disponibilités, vos compétences et votre CV.</span></button>
   </div>
   ${installHint()}
   <div class="home-links">
     <a class="btn" href="mailto:${CONTACT}?subject=${encodeURIComponent((AG + " · ") + S.me.prenom + " " + (S.me.nom || ""))}">Contacter ${AG}</a>
     ${isStandalone() || installHint() ? "" : `<a class="btn ghost" href="installer.php" data-install>Installer l'appli sur mon téléphone</a>`}
   </div>
   <div class="home-help"><a href="confidentialite.php">Vos données personnelles</a></div>`;
  $("#t-cr").onclick = goForm; if ($("#t-manque")) $("#t-manque").onclick = openFiche; $("#t-mine").onclick = openMine; if (S.planVis) { $("#t-plan").onclick = openPlan; $("#t-rel").onclick = openRel; $("#t-ct").onclick = () => openCt(); if ($("#t-of")) $("#t-of").onclick = openOf; if ($("#t-ofn")) $("#t-ofn").onclick = openOf; document.querySelectorAll("[data-rappel]").forEach(x => x.onclick = openPlan); document.querySelectorAll("[data-pp]").forEach(b => b.onclick = () => prendrePoste(b)); }
  // Planning et relevés : visibles seulement quand l'agence les a ouverts (ou en aperçu).
  if (S.nbPlan === undefined && !(S.L && S.L.apercu && !S.me)) { S.nbPlan = 0;
    Promise.all([api("mes_missions"), api("mes_releves"), api("mes_contrats").catch(() => ({contrats:[]})), api("mes_offres").catch(() => ({offres:[]}))]).then(([r, q, k, of]) => { S.offres = of.offres; S.nbOf = of.offres.filter(x => !x.reponse).length; S.ctrs = k.contrats; S.nbCt = k.contrats.filter(x => x.statut === "envoye").length; const t = today(); S.manque = r.manque || []; S.plan = r.missions; S.nbPlan = r.missions.filter(m => m.date >= t).length; S.nextM = r.missions.find(m => m.date >= t) || null;
      S.rels = q.releves; S.nbRel = q.releves.filter(x => x.statut === "envoye").length; S.planVis = !!r.visible; if (S.view === "home" && S.planVis) vHome(); }).catch(() => {}); }
  $("#t-form").onclick = openFormations; $("#t-fiche").onclick = openFiche; $("#t-docs").onclick = () => openDocs(S.docsNew ? "sal" : "adm");
  if (S.docsNew === undefined && !(S.L && S.L.apercu && !S.me)) { S.docsNew = 0; api("mes_documents").then(r => { S.docs = r; docsCompte(); if (S.view === "home") vHome(); }).catch(() => {}); }
  if ($("#t-evt")) $("#t-evt").onclick = () => openEvt();
  if ($("#t-mc")) $("#t-mc").onclick = () => openMc();
  if (S.mcM === undefined && !(S.L && S.L.apercu && !S.me)) { S.mcM = []; api("mc_missions").then(r => { S.mcM = r.missions; S.mcCfg = r; if (S.view === "home" && S.mcM.length) vHome(); }).catch(() => {}); }
  if ($("#t-fae")) $("#t-fae").onclick = openFae;
  if (S.fae === undefined && !(S.L && S.L.apercu && !S.me)) { S.fae = {ae:false, factures:[]}; api("mes_factures").then(r => { S.fae = r; if (S.view === "home" && r.ae) vHome(); }).catch(() => {}); }
  if (S.evM === undefined && !(S.L && S.L.apercu && !S.me)) { S.evM = []; api("evt_missions").then(r => { S.evM = r.missions; S.evCfg = r; if (S.view === "home" && S.evM.length) vHome(); }).catch(() => {}); }
  if ($("#t-dispo")) $("#t-dispo").onclick = openDispo; if ($("#t-ndf")) $("#t-ndf").onclick = openNdf; pushBind();
  bindInstall();
}

/* ---------- Mon planning : récap, calendrier, liste ---------- */
const pMin = m => { const t = x => { const r = /^(\d{1,2}):(\d{2})/.exec(x || ""); return r ? +r[1] * 60 + +r[2] : 0; }; return Math.max(0, t(m.fin) - t(m.debut) - (+m.pause || 0)); };
const pH = m => Math.floor(m / 60) + "h" + (m % 60 ? String(m % 60).padStart(2, "0") : "");
const pMoisTxt = ym => new Date(ym + "-15T12:00:00").toLocaleDateString("fr-FR", {month:"long", year:"numeric"});
const pLong = iso => new Date(iso + "T12:00:00").toLocaleDateString("fr-FR", {weekday:"long", day:"numeric", month:"long"});
async function openPlan(){ S.view = "plan"; S.calM = S.calM || today().slice(0, 7); S.calD = null; try { S.plan = (await api("mes_missions")).missions; } catch(e) { toast(e.message); S.plan = []; } render(); scrollTo(0, 0); }
// Trajet domicile ↔ point de vente : distance aller-retour et liens GPS (pas de montant : les frais sont calculés par l'agence).
const pEur = v => (+v).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €";
function pTrajet(m){
  const t = m.trajet; if (!t) return "";
  const q = encodeURIComponent(t.dest), gps = `<div class="trj-go"><a class="btn ghost sm" href="https://www.google.com/maps/dir/?api=1&destination=${q}" target="_blank" rel="noopener">S'y rendre</a><a class="btn ghost sm" href="https://waze.com/ul?q=${q}&navigate=yes" target="_blank" rel="noopener">Waze</a></div>`;
  if (t.mode === "tc") return `<div class="trj"><div><b>Transports en commun</b></div><div class="muted">Indemnité de transport en commun : <b>${pEur(t.montant)}</b> par journée (aller-retour)</div>${gps}</div>`;
  if (t.sans_adresse && !t.depart) return `<div class="trj"><span class="muted">Ajoutez votre adresse dans « Ma fiche » pour voir vos kilomètres.</span>${gps}</div>`;
  if (t.km_ar == null) return `<div class="trj">${gps}</div>`;
  return `<div class="trj"><div><b>${t.approx ? "environ " : ""}${t.km_ar} km</b> aller-retour depuis ${t.depart ? esc(t.depart) : "chez vous"}</div>${gps}</div>`;
}
function vPlan(){
  const t = today(), L = S.plan || [], next = L.filter(m => m.date >= t), ym = S.calM || t.slice(0, 7);
  const duMois = L.filter(m => m.date.slice(0, 7) === ym), nx = next[0];
  // Récap du mois affiché
  const parCli = {}; for (const m of duMois) { const k = m.client || m.poste || "Animation"; parCli[k] = (parCli[k] || 0) + 1; }
  const recap = `<div class="card accent"><h2>Mon planning</h2>
    ${nx ? `<div class="pl-next"><div class="hint">Prochaine animation</div><b>${esc(pLong(nx.date))}</b><div>${esc(nx.magasin || "Point de vente à préciser")}</div><div class="muted">${esc(nx.debut)} à ${esc(nx.fin)}${nx.client ? " · " + esc(nx.client) : ""}</div></div>` : `<div class="muted">Aucune animation à venir pour l'instant.</div>`}
    <div class="pl-kpis"><div><b>${next.length}</b><span>à venir</span></div><div><b>${new Set(duMois.map(m => m.date)).size}</b><span>jour${new Set(duMois.map(m => m.date)).size > 1 ? "s" : ""} en ${esc(pMoisTxt(ym).split(" ")[0])}</span></div><div><b>${pH(duMois.reduce((a, m) => a + pMin(m), 0))}</b><span>heures en ${esc(pMoisTxt(ym).split(" ")[0])}</span></div></div>
    ${Object.keys(parCli).length ? `<div class="hint">${Object.entries(parCli).map(([k, n]) => esc(k) + " : " + n).join(" · ")}</div>` : ""}</div>`;
  // Calendrier du mois
  const [y, mo] = ym.split("-").map(Number), first = new Date(y, mo - 1, 1), nbJ = new Date(y, mo, 0).getDate(), dec = (first.getDay() + 6) % 7;
  const byD = {}; for (const m of duMois) (byD[m.date] = byD[m.date] || []).push(m);
  let cells = ""; for (let i = 0; i < dec; i++) cells += `<span></span>`;
  for (let d = 1; d <= nbJ; d++) { const iso = ym + "-" + String(d).padStart(2, "0"), ms = byD[iso];
    cells += `<button type="button" class="cal-d${ms ? " on" : ""}${iso === t ? " today" : ""}${iso < t ? " past" : ""}${S.calD === iso ? " sel" : ""}" ${ms ? `data-cd="${iso}"` : "disabled"}>${d}${ms ? `<i></i>` : ""}</button>`; }
  const sel = S.calD && byD[S.calD];
  const item = m => { const d = new Date(m.date + "T12:00:00"); return `<div class="plan-i${m.date === t ? " today" : ""}"><div class="plan-d"><b>${d.getDate()}</b><span>${d.toLocaleDateString("fr-FR", {weekday:"short"})}</span></div>
      <div class="plan-c"><b>${esc(m.magasin || "Point de vente à préciser")}</b><div>${esc(m.poste)}${m.client ? " · " + esc(m.client) : ""}</div><div class="muted">${esc(m.debut)} à ${esc(m.fin)}${+m.pause ? ", pause " + pH(+m.pause) : ""} · ${pH(pMin(m))}${m.notes ? "<br>" + esc(m.notes) : ""}</div>${pTrajet(m)}</div></div>`; };
  const cal = `<div class="card"><div class="cal-h"><button class="ghost" id="cal-p" aria-label="Mois précédent">‹</button><b>${esc(pMoisTxt(ym))}</b><button class="ghost" id="cal-n" aria-label="Mois suivant">›</button></div>
    <div class="cal-g">${["L","M","M","J","V","S","D"].map(x => `<span class="cal-w">${x}</span>`).join("")}${cells}</div>
    ${sel ? `<div style="margin-top:8px">${sel.map(item).join("")}</div>` : `<div class="hint" style="margin-top:6px">Touchez un jour marqué pour voir le détail.</div>`}</div>`;
  let cur = "", list = ""; for (const m of next) { const k = m.date.slice(0, 7); if (k !== cur) { list += `<h2 class="plan-m">${esc(pMoisTxt(k))}</h2>`; cur = k; } list += item(m); }
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Accueil</button></div>${recap}${cal}
    <div class="card"><h2>Toutes mes animations à venir</h2>${list || `<div class="empty">Aucune animation prévue pour l'instant.</div>`}<div class="hint" style="margin-top:8px">En cas d'empêchement, prévenez ${AG} au plus vite.</div></div>`;
  $("#back").onclick = goHome;
  const mv = k => { const d = new Date(y, mo - 1 + k, 15); S.calM = d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0"); S.calD = null; vPlan(); };
  $("#cal-p").onclick = () => mv(-1); $("#cal-n").onclick = () => mv(1);
  document.querySelectorAll("[data-cd]").forEach(b => b.onclick = () => { S.calD = S.calD === b.dataset.cd ? null : b.dataset.cd; vPlan(); });
}

/* ---------- Signature au doigt (relevés, contrats) ---------- */
function sigPad(c){
  const x = c.getContext("2d"); let drawn = 0, on = false, last = null;
  const fit = () => { const r = c.getBoundingClientRect(), k = window.devicePixelRatio || 1; c.width = r.width * k; c.height = r.height * k; x.setTransform(k, 0, 0, k, 0, 0); x.lineWidth = 2.2; x.lineCap = "round"; x.lineJoin = "round"; x.strokeStyle = "#1A1512"; drawn = 0; }; fit();
  const pt = e => { const r = c.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
  c.onpointerdown = e => { on = true; last = pt(e); c.setPointerCapture(e.pointerId); e.preventDefault(); };
  c.onpointermove = e => { if (!on) return; const p = pt(e); x.beginPath(); x.moveTo(...last); x.lineTo(...p); x.stroke(); drawn += Math.hypot(p[0] - last[0], p[1] - last[1]); last = p; e.preventDefault(); };
  c.onpointerup = c.onpointercancel = () => { on = false; };
  return {clear:fit, drawn:() => drawn, data:() => c.toDataURL("image/png")};
}

/* ---------- Missions proposées ---------- */
async function openOf(){ S.view = "of"; try { S.offres = (await api("mes_offres")).offres; S.nbOf = S.offres.filter(x => !x.reponse).length; } catch(e) { toast(e.message); S.offres = []; } render(); scrollTo(0, 0); }
function vOf(){
  const L = S.offres || [];
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Accueil</button></div>
    <div class="card accent"><h2>Missions proposées</h2><div class="muted">Cochez la ou les dates qui vous conviennent (un seul magasin par jour), puis « Je postule ». ${AG} vous confirme si vous êtes retenu(e).</div></div>
    ${L.map(o => { const occ = o.occupe || [], edit = S.ofEdit === o.id || !o.reponse;
      const choisies = o.slots.filter(x => o.dates.includes(x.key));
      if (!edit) return `<div class="card"><div><b>${esc(o.client)}</b>${o.objet ? `<div class="muted">${esc(o.objet)}</div>` : ""}</div>
        ${o.retenu ? `<div class="of-ok"><b>Vous êtes retenu(e)</b>, la mission est dans votre planning.</div>`
          : o.reponse === "oui" ? `<div class="of-ok"><b>Votre candidature est prise en compte, ${AG} revient vers vous.</b><div class="hint">Dates choisies : ${choisies.map(x => esc(fmtD(x.date)) + " à " + esc(x.magasin)).join(", ")}</div></div>`
          : `<div class="of-ok" style="background:var(--sand)">Vous avez répondu : pas disponible. Merci !</div>`}
        ${o.retenu ? "" : `<div class="btns"><button class="ghost" data-ofmod="${o.id}">Modifier ma réponse</button></div>`}</div>`;
      const sel = o.reponse === "oui" ? o.dates : [];
      return `<div class="card"><div><b>${esc(o.client)}</b>${o.objet ? `<div class="muted">${esc(o.objet)}</div>` : ""}</div>
        <div>${o.slots.map(x => { const pris = occ.includes(x.key);
          return `<label class="ck of-sl${pris ? " off" : ""}"><input type="checkbox" data-ofd="${o.id}" data-day="${x.date}" value="${esc(x.key)}" ${sel.includes(x.key) && !pris ? "checked" : ""} ${pris ? "disabled" : ""}><span><b>${esc(fmtD(x.date))}</b> · ${esc(x.magasin)}<br><span class="muted">${esc(x.debut)} à ${esc(x.fin)}${pris ? " · vous travaillez déjà ce jour-là" : ""}</span></span></label>`; }).join("")}</div>
        ${o.message ? `<div class="muted">${esc(o.message)}</div>` : ""}
        <div class="btns"><button class="primary" data-ofoui="${o.id}">Je postule</button><button class="ghost" data-ofnon="${o.id}">Pas disponible</button></div></div>`; }).join("") || `<div class="card"><div class="empty">Aucune mission proposée en ce moment.</div></div>`}`;
  $("#back").onclick = goHome;
  // Un seul magasin par jour : cocher un magasin décoche les autres du même jour.
  document.querySelectorAll("[data-ofd]").forEach(c => c.onchange = () => { if (c.checked) document.querySelectorAll(`[data-ofd="${c.dataset.ofd}"][data-day="${c.dataset.day}"]`).forEach(x => { if (x !== c) x.checked = false; }); });
  const rep = async (id, reponse, b) => { const dates = [...document.querySelectorAll(`[data-ofd="${id}"]:checked`)].map(i => i.value);
    if (reponse === "oui" && !dates.length) return toast("Cochez au moins une date.");
    b.disabled = true; try { await api("offre_repondre", {offre_id:id, reponse, dates}); S.ofEdit = null; await openOf(); } catch(e) { toast(e.message); b.disabled = false; } };
  document.querySelectorAll("[data-ofoui]").forEach(b => b.onclick = () => rep(+b.dataset.ofoui, "oui", b));
  document.querySelectorAll("[data-ofnon]").forEach(b => b.onclick = () => rep(+b.dataset.ofnon, "non", b));
  document.querySelectorAll("[data-ofmod]").forEach(b => b.onclick = () => { S.ofEdit = +b.dataset.ofmod; vOf(); });
}

/* ---------- Mes contrats ---------- */
async function openCt(id){ S.view = "ct"; S.ctId = id || null; try { const q = await api("mes_contrats"); S.ctrs = q.contrats; S.nbCt = q.contrats.filter(x => x.statut === "envoye").length; } catch(e) { toast(e.message); S.ctrs = []; } render(); scrollTo(0, 0); }
function vCt(){
  const L = S.ctrs || [], C = S.ctId && L.find(x => x.id === S.ctId);
  const per = x => x.du ? fmtD(x.du) + (x.au !== x.du ? " au " + fmtD(x.au) : "") : "plus aucune date";
  if (!C) {
    $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Accueil</button></div>
      <div class="card accent"><h2>Mes contrats</h2><div class="muted">Vos contrats de travail avec ${AGN}. Si vos dates changent après la signature, vous recevez un avenant à signer.</div></div>
      <div class="card">${L.length ? L.map(x => `<button class="rel-i" data-ct="${x.id}"><span><b>${esc(x.titre)}</b><br><span class="muted">${esc(x.client)} · ${esc(per(x))}</span></span>
        ${x.statut === "signe" ? `<span class="pill ok">Signé</span>` : `<span class="pill warn">À signer</span>`}</button>`).join("") : `<div class="empty">Aucun contrat pour l'instant.</div>`}</div>`;
    $("#back").onclick = goHome; document.querySelectorAll("[data-ct]").forEach(b => b.onclick = () => { S.ctId = +b.dataset.ct; render(); scrollTo(0, 0); }); return;
  }
  const sign = C.statut === "envoye", tot = C.lignes.reduce((a, l) => a + l.salaire, 0), th = C.lignes.reduce((a, l) => a + l.heures, 0);
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Mes contrats</button></div>
    <div class="card accent"><h2>${esc(C.titre)}</h2><div><b>${esc(C.client)}</b></div><div class="muted">${esc(C.operation)}</div>
      <div class="pl-kpis" style="margin-top:6px"><div><b>${new Set(C.lignes.map(l => l.date)).size}</b><span>jours</span></div><div><b>${dur(Math.round(th * 60))}</b><span>heures</span></div><div><b>${Math.round(tot)} €</b><span>brut estimé</span></div></div>
      <a class="btn ${sign ? "primary" : "ghost"}" href="api.php?action=contrat_pdf_mien&id=${C.id}" target="_blank" rel="noopener" id="ct-read" style="text-align:center">${sign ? "Lire mon " + (C.avenant ? "avenant" : "contrat") + " en entier (PDF)" : "Voir le PDF signé"}</a></div>
    <div class="card"><h2>Mes journées</h2>${C.lignes.map(l => `<div class="rel-l"><div><b>${esc(fmtD(l.date))}</b><div>${esc(l.magasin || "Point de vente à préciser")}</div><div class="muted">${esc(l.debut)} à ${esc(l.fin)}${+l.pause ? ", pause " + dur(+l.pause) : ""}${l.maj ? " · " + esc(l.maj_txt || "majoré 100 %") : ""}</div></div><b>${dur(Math.round(l.heures * 60))}</b></div>`).join("") || `<div class="empty">Plus aucune date.</div>`}</div>
    ${sign ? `<div class="card"><h2>Signer</h2><div class="muted">Lisez le document en entier avant de signer. Si quelque chose ne va pas, ne signez pas et contactez ${AG}.</div>
      <label>Écrivez « lu et approuvé »<input id="cs-ment" autocomplete="off" placeholder="lu et approuvé"></label>
      <label>Votre prénom et votre nom<input id="cs-nom" autocomplete="name" value="${esc(C.nom)}"></label>
      <div><div class="hint" style="margin-bottom:4px">Signez avec le doigt dans le cadre</div><canvas id="cs-pad" class="rel-pad"></canvas><button class="ghost" id="cs-clear" style="margin-top:6px">Effacer</button></div>
      <label class="ck"><input type="checkbox" id="cs-ok"><span>J'ai lu ${C.avenant ? "cet avenant" : "mon contrat"} et j'en accepte les termes.</span></label>
      <button class="primary" id="cs-go">Signer</button></div>`
    : `<div class="card"><h2>Signé</h2><div>Par <b>${esc(C.signe_nom)}</b> le ${esc(fmtD(C.signe_le.slice(0, 10)))} à ${esc(C.signe_le.slice(11, 16))}</div>${C.signe_test ? `<div class="hint">Signature de test.</div>` : ""}</div>`}`;
  $("#back").onclick = () => { S.ctId = null; render(); scrollTo(0, 0); };
  if (!sign) return;
  const P = sigPad($("#cs-pad")); $("#cs-clear").onclick = P.clear;
  $("#cs-go").onclick = async () => { if (P.drawn() < 40) return toast("Signez dans le cadre avec le doigt.");
    const b = $("#cs-go"); b.disabled = true;
    try { await api("contrat_signer", {id:C.id, mention:$("#cs-ment").value, nom:$("#cs-nom").value.trim(), certifie:$("#cs-ok").checked ? 1 : 0, signature:P.data()}); toast("Signé, merci !"); await openCt(C.id); }
    catch(e) { toast(e.message); b.disabled = false; } };
}

/* ---------- Mes relevés d'heures ---------- */
const dur = m => Math.floor(m / 60) + "h" + (m % 60 ? String(m % 60).padStart(2, "0") : "");
async function openRel(m){ S.view = "rel"; S.relM = m || null; try { const q = await api("mes_releves"); S.rels = q.releves; S.nbRel = q.releves.filter(x => x.statut === "envoye").length; } catch(e) { toast(e.message); S.rels = []; } render(); scrollTo(0, 0); }
function vRel(){
  const L = S.rels || [], R = S.relM && L.find(x => x.mois === S.relM);
  if (!R) {
    $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Accueil</button></div>
      <div class="card accent"><h2>Mes relevés d'heures</h2><div class="muted">Chaque mois, ${AG} vérifie vos heures d'après votre planning, puis vous les envoie ici pour signature.</div></div>
      <div class="card">${L.length ? L.map(x => `<button class="rel-i" data-rm="${x.mois}"><span><b>${esc(x.mois_txt.charAt(0).toUpperCase() + x.mois_txt.slice(1))}</b><br><span class="muted">${x.jours} jour${x.jours > 1 ? "s" : ""} · ${dur(x.minutes)}</span></span>
        ${x.statut === "signe" ? `<span class="pill ok">Signé</span>` : `<span class="pill warn">À signer</span>`}</button>`).join("") : `<div class="empty">Aucun relevé pour l'instant.</div>`}</div>`;
    $("#back").onclick = goHome; document.querySelectorAll("[data-rm]").forEach(b => b.onclick = () => { S.relM = b.dataset.rm; render(); scrollTo(0, 0); }); return;
  }
  const sign = R.statut === "envoye";
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Mes relevés</button></div>
    <div class="card accent"><h2>Relevé d'heures · ${esc(R.mois_txt)}</h2><div class="rel-tot"><b>${dur(R.minutes)}</b><span class="muted">${R.jours} jour${R.jours > 1 ? "s" : ""} travaillé${R.jours > 1 ? "s" : ""}</span></div>
      ${R.envoye_le ? `<div class="hint">Vérifié par ${esc(R.envoye_par)} le ${esc(fmtD(R.envoye_le.slice(0, 10)))}</div>` : ""}</div>
    <div class="card">${R.lignes.map(l => `<div class="rel-l${l.exclu ? " x" : ""}"><div><b>${esc(fmtD(l.date))}</b><div>${esc(l.magasin || "Point de vente à préciser")}</div><div class="muted">${esc(l.debut)} à ${esc(l.fin)}${+l.pause ? ", pause " + dur(+l.pause) : ""}${l.note ? " · " + esc(l.note) : ""}${l.exclu ? " · non compté" : ""}</div></div><b>${dur(l.minutes)}</b></div>`).join("")}
      ${R.commentaire ? `<div class="muted" style="margin-top:8px">${esc(R.commentaire)}</div>` : ""}</div>
    ${sign ? `<div class="card"><h2>Signer</h2><div class="muted">Vérifiez vos heures. Si quelque chose ne va pas, ne signez pas et contactez ${AG}.</div>
      <label>Votre prénom et votre nom<input id="rs-nom" autocomplete="name" value="${esc((S.me.prenom || "") + " " + (S.me.nom || ""))}"></label>
      <div><div class="hint" style="margin-bottom:4px">Signez avec le doigt dans le cadre</div><canvas id="rs-pad" class="rel-pad"></canvas><button class="ghost" id="rs-clear" style="margin-top:6px">Effacer</button></div>
      <label class="ck"><input type="checkbox" id="rs-ok"><span>Je certifie l'exactitude des heures de ce relevé.</span></label>
      <button class="primary" id="rs-go">Signer mon relevé</button></div>`
    : `<div class="card"><h2>Signé</h2><div>Par <b>${esc(R.signe_nom)}</b> le ${esc(fmtD(R.signe_le.slice(0, 10)))} à ${esc(R.signe_le.slice(11, 16))}</div>${R.signe_test ? `<div class="hint">Signature de test (aperçu administrateur).</div>` : ""}</div>`}
    <div class="btns"><a class="btn ghost" href="api.php?action=releve_pdf_mien&mois=${R.mois}" target="_blank" rel="noopener">Voir le PDF</a></div>`;
  $("#back").onclick = () => { S.relM = null; render(); scrollTo(0, 0); };
  if (!sign) return;
  const P = sigPad($("#rs-pad")); $("#rs-clear").onclick = P.clear;
  $("#rs-go").onclick = async () => { if (P.drawn() < 40) return toast("Signez dans le cadre avec le doigt.");
    const b = $("#rs-go"); b.disabled = true;
    try { await api("releve_signer", {mois:R.mois, nom:$("#rs-nom").value.trim(), certifie:$("#rs-ok").checked ? 1 : 0, signature:P.data()}); toast("Relevé signé, merci !"); await openRel(R.mois); }
    catch(e) { toast(e.message); b.disabled = false; } };
}

/* ---------- Mes comptes rendus ---------- */
async function openMine(){ S.view = "mine"; try { S.mine = (await api("mine")).rapports; } catch(e) { toast(e.message); } render(); scrollTo(0, 0); }
function vMine(){
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Accueil</button><button class="primary" id="new">Nouveau compte rendu</button></div>
   <div class="card"><h2>Mes comptes rendus</h2><div class="muted">Touchez un compte rendu pour le revoir en entier.</div>
   ${S.mine.length ? `<div class="mylist">${S.mine.map(r => `<button type="button" class="myitem" data-r="${r.id}">
      <span class="d"><b>${new Date(r.date_animation + "T12:00:00").getDate()}</b>${new Date(r.date_animation + "T12:00:00").toLocaleDateString("fr-FR", {month:"short"})}</span>
      <span class="t"><b>${esc(r.marques)}</b><span class="muted">${esc(r.magasin)} · ${esc(r.ville)}</span></span><span class="ca">${money(r.ca, r.devise)}</span><span class="go">›</span></button>`).join("")}</div>`
   : `<div class="empty">Vos comptes rendus envoyés apparaîtront ici.</div>`}</div>`;
  $("#back").onclick = goHome; $("#new").onclick = goForm;
  document.querySelectorAll("[data-r]").forEach(b => b.onclick = () => vMineDetail(+b.dataset.r));
}
async function vMineDetail(id){
  let r; try { r = await api("mine_detail", null, "id=" + id); } catch(e) { return toast(e.message); }
  const l = r.marques[0] || {}, dev = r.devise;
  const sec = (t, v) => `<div class="cr-sec"><div class="cr-h">${t}</div>${v && String(v).trim() ? `<div class="cr-t">${esc(v).replace(/\n/g, "<br>")}</div>` : `<div class="cr-e">Rien à signaler</div>`}</div>`;
  const conc = r.concurrence.concat(r.concurrence_autre ? [r.concurrence_autre] : []);
  const kpis = [];
  (l.parts || []).forEach(p => kpis.push([money(p.ca, dev) || "–", "CA " + p.nom + (p.objectif != null && +p.objectif > 0 ? " · objectif " + money(p.objectif, dev) + " · " + ((p.ca / p.objectif - 1) >= 0 ? "+" : "") + Math.round((p.ca / p.objectif - 1) * 100) + " %" : "")]));
  kpis.push([money(l.ca, dev) || "–", l.parts && l.parts.length ? "CA total" : "CA réalisé"]);
  if (l.objectif != null && +l.objectif > 0) { kpis.push([money(l.objectif, dev), "Objectif"]); if (l.ca != null) { const pc = Math.round((l.ca / l.objectif - 1) * 100); kpis.push([(pc >= 0 ? "+" : "") + pc + " %", "Atteinte"]); } }
  const photos = r.photos;
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Mes comptes rendus</button></div>
   <div class="cr">
     <div class="cr-hero"><div class="cr-k">Compte rendu d'animation</div><div class="cr-title">${esc(l.marque || "")}</div>${l.detail ? `<div class="cr-sub">${esc(l.detail)}</div>` : ""}
       <div class="cr-sub">${esc(new Date(r.date_animation + "T12:00:00").toLocaleDateString("fr-FR", {weekday:"long", day:"numeric", month:"long", year:"numeric"}))}</div></div>
     <div class="cr-meta"><div><span>Point de vente</span><b>${esc(r.magasin)}</b><i>${esc(r.ville)} · ${esc(r.zone)}</i></div><div><span>Fréquentation</span><b>${esc(r.frequentation)}</b></div></div>
     <div class="cr-kpis">${kpis.map(k => `<div><b>${k[0]}</b><span>${esc(k[1])}</span></div>`).join("")}</div>
     ${sec("Réactions des clientes", l.reactions)}${sec("Ruptures de produits", l.ruptures)}${sec("Testeurs manquants", l.testeurs)}
     <div class="cr-sec"><div class="cr-h">Intervenants concurrent(e)s présentes</div>${conc.length ? `<div class="chips">${conc.map(c => `<span class="chip" aria-pressed="false">${esc(c)}</span>`).join("")}</div>` : `<div class="cr-e">Aucune</div>`}</div>
     ${sec("Offres en cours", r.offres)}${sec("Faits marquants", r.faits)}${sec("Personnel et direction", r.staff)}
     ${photos.length ? `<div class="cr-sec"><div class="cr-h">Photos</div><div class="cr-ph">${photos.map(p => `<a href="${p.full}" target="_blank" rel="noopener"><img src="${p.url}" alt=""></a>`).join("")}</div></div>` : ""}
     <div class="cr-foot">Envoyé le ${esc(r.created_at.slice(0, 10).split("-").reverse().join("/"))} à ${esc(r.created_at.slice(11, 16).replace(":", "h"))}${r.modifie_le ? (" · corrigé par " + AGN) : ""}</div>
   </div>
   <div class="card"><h2>Recevoir ce compte rendu en PDF</h2>
     <label>Votre adresse e-mail<input id="pdf-mail" type="email" inputmode="email" value="${esc(S.me.email || "")}"></label>
     <button class="primary" id="pdf-go">M'envoyer le PDF</button></div>`;
  $("#back").onclick = openMine;
  $("#pdf-go").onclick = async () => { const b = $("#pdf-go"); b.disabled = true; b.textContent = "Envoi…";
    try { const res = await api("mine_pdf", {id, email:$("#pdf-mail").value.trim()}); S.me.email = $("#pdf-mail").value.trim(); toast(res.message); }
    catch(e) { toast(e.message); } b.disabled = false; b.textContent = "M'envoyer le PDF"; };
  scrollTo(0, 0);
}
boot();

/* ---------- Notifications sur le téléphone ---------- */
const PUSH_OK = "serviceWorker" in navigator && "PushManager" in window && "Notification" in window;
const estIOS = () => /iphone|ipad|ipod/i.test(navigator.userAgent);
const b64uBytes = s => { const p = "=".repeat((4 - s.length % 4) % 4), b = atob((s + p).replace(/-/g, "+").replace(/_/g, "/")); return Uint8Array.from([...b].map(c => c.charCodeAt(0))); };
async function pushAbonner(silencieux){
  try { const reg = await navigator.serviceWorker.register("sw.js"); await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();
    if (!sub) { const k = (await api("push_cle")).cle; if (!k) throw new Error("Notifications pas encore disponibles sur le serveur.");
      sub = await reg.pushManager.subscribe({userVisibleOnly:true, applicationServerKey:b64uBytes(k)}); }
    await api("push_abonner", sub.toJSON()); if (!silencieux) toast("Notifications activées sur ce téléphone");
  } catch(e) { if (!silencieux) toast(e.message || "Activation impossible"); }
}
/** Carte d'accueil : proposer les notifications (sur iPhone, seulement une fois l'appli installée sur l'écran d'accueil). */
function pushCarte(){
  if (S.L && S.L.apercu) return "";
  if (!PUSH_OK) return estIOS() && !isStandalone() ? `<div class="card pp"><b>🔔 Recevoir les notifications de ${AG}</b><div class="muted">Sur iPhone, installez d'abord l'appli sur l'écran d'accueil (bouton Partager, puis « Sur l'écran d'accueil »), ouvrez-la depuis l'icône, puis activez les notifications ici.</div></div>` : "";
  if (Notification.permission === "granted") { if (!S.pushFait) { S.pushFait = true; pushAbonner(true); } return ""; }
  if (Notification.permission === "denied" || S.pushNon) return "";
  return `<div class="card accent pp"><b>🔔 Recevoir les notifications de ${AG}</b><div class="muted">Missions proposées, planning, contrats et relevés à signer, documents : une notification sur votre téléphone dès que ${AG} publie quelque chose.</div>
    <div class="btns"><button class="primary" id="push-on">Activer</button><button class="ghost" id="push-non">Plus tard</button></div></div>`;
}
function pushBind(){
  if ($("#push-on")) $("#push-on").onclick = async () => { const p = await Notification.requestPermission(); if (p === "granted") await pushAbonner(false); else toast("Notifications refusées : vous pourrez les autoriser dans les réglages du téléphone."); vHome(); };
  if ($("#push-non")) $("#push-non").onclick = () => { S.pushNon = true; vHome(); };
}
/** Ouverture depuis une notification : index.php#plan, #of, #ct, #rel, #docs, #ndf, #dispo. */
function ouvrirAncre(){
  const h = location.hash.slice(1); if (!h) return false; history.replaceState(null, "", location.pathname + location.search);
  const f = {plan:openPlan, of:() => openOf(), ct:() => openCt(), rel:() => openRel(), docs:() => openDocs("sal"), ndf:openNdf, dispo:openDispo}[h]; if (f) { f(); return true; } return false;
}

/* ---------- Mes disponibilités ---------- */
async function openDispo(){ S.view = "dispo"; S.dM = S.dM || today().slice(0, 7); try { const r = await api("mes_dispos"); S.dispos = r.dispos || {}; S.dMiss = new Set(r.missions || []); } catch(e) { toast(e.message); S.dispos = {}; S.dMiss = new Set(); } render(); scrollTo(0, 0); }
function vDispo(){
  const [y, m] = S.dM.split("-").map(Number), first = new Date(y, m - 1, 1), off = (first.getDay() + 6) % 7, n = new Date(y, m, 0).getDate(), t = today();
  let cells = ""; for (let k = 0; k < off; k++) cells += `<span></span>`;
  for (let d = 1; d <= n; d++) { const iso = `${y}-${String(m).padStart(2, "0")}-${String(d).padStart(2, "0")}`, e = S.dispos[iso] || "", mi = S.dMiss.has(iso), pass = iso < t;
    cells += `<button type="button" class="dp-d${e === "o" ? " oui" : e === "n" ? " non" : ""}${mi ? " mi" : ""}" data-dj="${iso}" ${pass || mi ? "disabled" : ""}>${d}${mi ? "<i>mission</i>" : e === "o" ? "<i>dispo</i>" : e === "n" ? "<i>indispo</i>" : ""}</button>`; }
  const nb = k => Object.entries(S.dispos).filter(([j, v]) => j.startsWith(S.dM) && v === k).length;
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Accueil</button></div>
    <div class="card accent"><h2>Mes disponibilités</h2><div class="muted">Touchez un jour : <b>dispo</b>, puis <b>indispo</b>, puis effacé. ${AG} vous propose en priorité des missions les jours où vous êtes disponible, et ne vous sollicite pas les jours indisponibles.</div></div>
    <div class="card"><div class="gcal-h"><button type="button" class="ghost" data-dm="-1">‹</button><b>${pMoisTxt(S.dM)}</b><button type="button" class="ghost" data-dm="1">›</button></div>
      <div class="dp-g">${["lun","mar","mer","jeu","ven","sam","dim"].map(j => `<i>${j}</i>`).join("")}${cells}</div>
      <div class="hint" style="margin-top:8px">${nb("o")} jour${nb("o") > 1 ? "s" : ""} dispo · ${nb("n")} indispo ce mois-ci</div>
      <div class="btns"><button class="ghost" id="dp-we">Dispo tous les week-ends du mois</button><button class="ghost" id="dp-tout">Dispo tout le mois</button><button class="ghost" id="dp-raz">Tout effacer</button></div></div>`;
  $("#back").onclick = goHome;
  document.querySelectorAll("[data-dm]").forEach(b => b.onclick = () => { const d = new Date(S.dM + "-15T12:00:00"); d.setMonth(d.getMonth() + +b.dataset.dm); S.dM = d.toISOString().slice(0, 7); vDispo(); });
  const poser = async (jours, etat) => { if (S.L && S.L.apercu) return toast("Aperçu : lecture seule.");
    try { await api("dispo_set", {jours, etat}); jours.forEach(j => { if (etat) S.dispos[j] = etat; else delete S.dispos[j]; }); vDispo(); } catch(e) { toast(e.message); } };
  document.querySelectorAll("[data-dj]").forEach(b => b.onclick = () => { const j = b.dataset.dj, e = S.dispos[j] || ""; poser([j], e === "" ? "o" : e === "o" ? "n" : ""); });
  const jours = f => { const L = []; for (let d = 1; d <= n; d++) { const iso = `${y}-${String(m).padStart(2, "0")}-${String(d).padStart(2, "0")}`; if (iso >= t && !S.dMiss.has(iso) && f(new Date(y, m - 1, d))) L.push(iso); } return L; };
  $("#dp-we").onclick = () => poser(jours(d => d.getDay() === 0 || d.getDay() === 6), "o");
  $("#dp-tout").onclick = () => poser(jours(() => true), "o");
  $("#dp-raz").onclick = () => poser(jours(() => true), "");
}

/* ---------- Mes notes de frais ---------- */
async function openNdf(){ S.view = "ndf"; try { S.N = await api("mes_ndf"); } catch(e) { return toast(e.message); } render(); scrollTo(0, 0); }
function vNdf(){
  const N = S.N, st = {a_valider:["à valider", "p-warn"], validee:["validée", "p-ok"], refusee:["refusée", "p-bad"], payee:["payée", ""]};
  const eur = v => (+v).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €";
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Accueil</button></div>
    <div class="card accent"><h2>Mes notes de frais</h2><div class="muted">Parking, carburant, péage, transport… pris pendant une mission. Prenez le ticket en photo : ${AG} valide la note et vous la rembourse avec votre paye du mois.</div></div>
    <div class="card"><h2>Nouvelle note</h2>
      <div class="row"><label>Date de la dépense<input type="date" id="nf-j" value="${today()}" max="${today()}"></label><label>Type<select id="nf-t">${Object.entries(N.types).map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join("")}</select></label></div>
      <div class="row"><label>Montant TTC (€)<input id="nf-m" inputmode="decimal" placeholder="Ex. 4,50"></label>
        <label>Mission<select id="nf-o"><option value="">Choisir…</option>${(N.missions || []).map((x, i) => `<option value="${i}">${esc(fmtD(x.date_mission))} · ${esc(x.magasin || "")}${x.objet ? " · " + esc(x.objet) : ""}</option>`).join("")}</select></label></div>
      <label>Commentaire <span class="hint">facultatif</span><input id="nf-c" placeholder="Ex. parking du centre commercial"></label>
      <div class="shoots"><label class="shoot">Photographier le ticket<input type="file" accept="image/*" capture="environment" id="nf-f"></label><label class="shoot alt">Galerie ou PDF<input type="file" accept="image/*,application/pdf" id="nf-g"></label></div>
      <div class="hint" id="nf-fn"></div>
      <div class="btns"><button class="primary" id="nf-go">Envoyer la note</button></div></div>
    <div class="card"><h2>Mes notes</h2>${N.ndf.length ? `<div class="dc-list">${N.ndf.map(x => `<div class="dc-row"><div><b>${esc(x.type_txt)} · ${eur(x.montant)}</b> <span class="pill ${st[x.statut][1]}">${st[x.statut][0]}</span>
        <div class="hint">${esc(fmtD(x.jour))}${x.magasin ? " · " + esc(x.magasin) : ""}${x.commentaire ? " · " + esc(x.commentaire) : ""}${x.motif ? ` · <b>motif : ${esc(x.motif)}</b>` : ""}</div></div>
        <div class="dc-act"><a class="btn ghost sm" href="api.php?action=ndf_ticket&id=${x.id}" target="_blank" rel="noopener">Ticket</a>${x.statut === "a_valider" ? `<button class="ghost sm" data-nfx="${x.id}">Supprimer</button>` : ""}</div></div>`).join("")}</div>`
      : `<div class="empty">Aucune note pour l'instant.</div>`}</div>`;
  $("#back").onclick = goHome; let fic = null;
  ["#nf-f", "#nf-g"].forEach(s => $(s).onchange = e => { fic = e.target.files[0] || null; $("#nf-fn").textContent = fic ? "Ticket : " + fic.name : ""; });
  $("#nf-go").onclick = async () => { if (!fic) return toast("Prenez le ticket en photo.");
    const fd = new FormData(), mi = (N.missions || [])[+$("#nf-o").value]; fd.append("jour", $("#nf-j").value); fd.append("type", $("#nf-t").value); fd.append("montant", $("#nf-m").value); fd.append("commentaire", $("#nf-c").value);
    if ($("#nf-o").value !== "" && mi) { fd.append("devis_id", mi.devis_id || ""); fd.append("magasin", mi.magasin || ""); }
    fd.append("fichier", fic.type.startsWith("image/") ? await shrink(fic) : fic, fic.type.startsWith("image/") ? "ticket.jpg" : fic.name);
    const b = $("#nf-go"); b.disabled = true; try { S.N.ndf = (await api("ndf_ajouter", fd)).ndf; toast(("Note envoyée à " + AG)); vNdf(); } catch(e) { toast(e.message); b.disabled = false; } };
  document.querySelectorAll("[data-nfx]").forEach(b => b.onclick = async () => { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer"; return; }
    try { S.N.ndf = (await api("ndf_supprimer", {id:+b.dataset.nfx})).ndf; vNdf(); } catch(e) { toast(e.message); } });
}

/* ---------- Rapport d'événement (accueil et hôtessariat) ---------- */
const EVK = (m) => "amy_evt_" + (S.me ? S.me.id : 0) + "_" + m.devis_id + "_" + m.date;
async function openEvt(){ S.view = "evt"; S.evSel = null; try { const r = await api("evt_missions"); S.evM = r.missions; S.evCfg = r; } catch(e) { return toast(e.message); } render(); scrollTo(0, 0); }
function evDraft(m){ let d = null; try { d = JSON.parse(localStorage.getItem(EVK(m)) || "null"); } catch(e) {} return d && d.token ? d : {token:newToken(), affluence:"", visiteurs:"", contacts:"", documents:"", deroulement:"", demandes:"", points_forts:"", ameliorer:"", incidents:"", equipe:""}; }
function evSave(){ if (!S.evSel) return; try { localStorage.setItem(EVK(S.evSel), JSON.stringify(S.evD)); } catch(e) {} }
function vEvt(){
  const C = S.evCfg || {}, L = S.evM || [];
  if (!S.evSel) {
    $("#view").innerHTML = `<div class="card"><button class="ghost" id="ev-back">‹ Accueil</button><h2 style="margin-top:10px">Rapport d'événement</h2><div class="muted">Choisissez l'événement. ${L.some(x => x.chef) ? "En tant que chef d'équipe, vous faites le rapport pour toute l'équipe." : ""}</div></div>
      ${L.length ? L.map((m, i) => `<button class="card" data-evm="${i}" style="text-align:left;width:100%;font:inherit;color:inherit;cursor:pointer"><b>${esc(pLong(m.date))}</b> ${m.fait ? `<span class="pill p-ok">envoyé</span>` : `<span class="pill">à faire</span>`}
        <div>${esc(m.evenement || m.type || "Événement")}${m.client ? " · " + esc(m.client) : ""}</div><div class="muted">${esc(m.lieu || "")}${m.role ? " · " + esc(m.role) : ""}</div></button>`).join("") : `<div class="card"><div class="empty">Aucun événement ces 30 derniers jours.</div></div>`}`;
    $("#ev-back").onclick = goHome;
    document.querySelectorAll("[data-evm]").forEach(b => b.onclick = () => { const m = L[+b.dataset.evm]; if (m.fait) return toast("Rapport déjà envoyé pour cet événement."); S.evSel = m; S.evD = evDraft(m); S.evPh = []; render(); scrollTo(0, 0);
      api("photos", null, "token=" + S.evD.token).then(r => { S.evPh = r.photos; if (S.view === "evt") render(); }).catch(() => {}); });
    return;
  }
  const m = S.evSel, d = S.evD, ph = S.evPh || [], champs = C.champs || {}, nbp = ph.filter(p => p.slot === "evt").length;
  const ta = (k) => k === "equipe" && !m.chef ? "" : `<label>${esc(champs[k][0])}${k === "deroulement" ? "" : ` <span class="hint">facultatif</span>`}<textarea data-ev="${k}" rows="${k === "deroulement" ? 4 : 3}" placeholder="${esc(champs[k][1])}">${esc(d[k] || "")}</textarea></label>`;
  $("#view").innerHTML = `<div class="card"><button class="ghost" id="ev-list">‹ Événements</button><h2 style="margin-top:10px">${esc(m.evenement || m.type || "Événement")}</h2>
      <div class="muted">${esc(pLong(m.date))} · ${esc(m.lieu || "")}${m.client ? " · " + esc(m.client) : ""}${m.role ? " · " + esc(m.role) : ""}</div>
      ${m.chef && m.equipe.length ? `<div class="hint" style="margin-top:8px"><b>Votre équipe ce jour-là</b><br>${m.equipe.map(x => esc(x.nom) + (x.role ? " (" + esc(x.role) + ")" : "") + (x.telephone ? ` · <a href="tel:${esc(x.telephone)}">${esc(x.telephone)}</a>` : "")).join("<br>")}</div>` : ""}</div>
    <div class="card"><h2>Les chiffres</h2>
      <label>Affluence<select data-ev="affluence"><option value="">Choisir…</option>${(C.affluence || []).map(a => `<option ${d.affluence === a ? "selected" : ""}>${esc(a)}</option>`).join("")}</select></label>
      <div class="row"><label>Visiteurs accueillis <span class="hint">estimation</span><input data-ev="visiteurs" inputmode="numeric" value="${esc(d.visiteurs)}"></label><label>Contacts collectés<input data-ev="contacts" inputmode="numeric" value="${esc(d.contacts)}"></label></div>
      <label>Documents ou cadeaux remis<input data-ev="documents" inputmode="numeric" value="${esc(d.documents)}"></label></div>
    <div class="card"><h2>La journée</h2>${Object.keys(champs).map(ta).join("")}</div>
    <div class="card"><h2>Photos</h2><div class="muted">Stand, accueil, affluence, équipe en tenue : ${C.max_photos || 8} photos maximum, sans visiteur reconnaissable.</div>
      <div class="photos">${ph.filter(p => p.slot === "evt").map(p => `<div class="ph"><img src="${p.url}" alt=""><button class="ghost sm" data-evdel="${p.id}">Retirer</button></div>`).join("")}</div>
      ${nbp < (C.max_photos || 8) ? `<label class="btn">Ajouter des photos<input type="file" id="ev-ph" accept="image/*" multiple hidden></label>` : ""}</div>
    <div class="sticky-submit"><button class="primary" id="ev-go">Envoyer le rapport</button></div>`;
  $("#ev-list").onclick = () => { S.evSel = null; render(); };
  document.querySelectorAll("[data-ev]").forEach(i => i.oninput = i.onchange = () => { d[i.dataset.ev] = i.value; evSave(); });
  document.querySelectorAll("[data-evdel]").forEach(b => b.onclick = async () => { try { S.evPh = (await api("delete_photo", {id:+b.dataset.evdel, token:d.token})).photos; render(); } catch(e) { toast(e.message); } });
  if ($("#ev-ph")) $("#ev-ph").onchange = async e => { const files = [...e.target.files].slice(0, (C.max_photos || 8) - nbp); toast("Envoi de " + files.length + " photo" + (files.length > 1 ? "s" : "") + "…");
    for (const f of files) { try { const fd = new FormData(); fd.append("photo", await shrink(f), "photo.jpg"); fd.append("token", d.token); fd.append("slot", "evt"); S.evPh = (await api("upload_photo", fd)).photos; } catch(x) { toast(x.message); } }
    render(); };
  $("#ev-go").onclick = async () => { const b = $("#ev-go"); b.disabled = true;
    try { await api("evt_submit", Object.assign({}, d, {devis_id:m.devis_id, date:m.date})); try { localStorage.removeItem(EVK(m)); } catch(e) {} m.fait = true; S.evSel = null; toast("Rapport envoyé, merci !"); goHome(); }
    catch(e) { toast(e.message); b.disabled = false; } };
}

/* ---------- Visite merchandising ---------- */
const MCK = m => "amy_mc_" + (S.me ? S.me.id : 0) + "_" + m.devis_id + "_" + m.date + "_" + m.magasin;
const hNow = () => new Date().toTimeString().slice(0, 5);
async function openMc(){ S.view = "mc"; S.mcSel = null; try { const r = await api("mc_missions"); S.mcM = r.missions; S.mcCfg = r; } catch(e) { return toast(e.message); } render(); scrollTo(0, 0); }
function mcDraft(m){ let d = null; try { d = JSON.parse(localStorage.getItem(MCK(m)) || "null"); } catch(e) {} return d && d.token ? d : {token:newToken(), arrivee:"", refs:{}, taches:[], lineaire_marque:"", lineaire_total:"", tablettes:"", emplacement:"", hauteur:"", plv_presente:0, plv_posee:0, promo_en_place:0, promo_txt:"", reserve:"", concurrence:"", interlocuteur:"", interlocuteur_fonction:"", commentaire:""}; }
function mcSave(){ if (S.mcSel) try { localStorage.setItem(MCK(S.mcSel), JSON.stringify(S.mcD)); } catch(e) {} }
function vMc(){
  const C = S.mcCfg || {}, L = S.mcM || [];
  if (!S.mcSel) {
    const by = {}; L.forEach((m, i) => (by[m.date + "|" + m.devis_id] = by[m.date + "|" + m.devis_id] || []).push([m, i]));
    $("#view").innerHTML = `<div class="card"><button class="ghost" id="mc-back">‹ Accueil</button><h2 style="margin-top:10px">Visite merchandising</h2><div class="muted">Une fiche par point d'intervention. En tournée, suivez l'ordre de l'itinéraire.</div></div>
      ${Object.values(by).map(G => { const m0 = G[0][0]; return `<div class="card"><b>${esc(pLong(m0.date))}</b><div>${esc(m0.operation)}${m0.client ? " · " + esc(m0.client) : ""}</div>${m0.tournee ? `<div class="hint">Tournée de ${m0.nb} points · ${esc(m0.debut)} à ${esc(m0.fin)}</div>` : ""}
        <div class="mc-list">${G.map(([m, i]) => `<button class="mc-it" data-mcm="${i}"><span class="mc-n">${m.tournee ? m.ordre : "•"}</span><span><b>${esc(m.magasin)}</b>${m.poste ? `<span class="hint"> · ${esc(m.poste)}</span>` : ""}</span>${m.fait ? `<span class="pill p-ok">envoyée</span>` : `<span class="pill">à faire</span>`}</button>`).join("")}</div></div>`; }).join("")
      || `<div class="card"><div class="empty">Aucune visite de merchandising ces 7 derniers jours.</div></div>`}`;
    $("#mc-back").onclick = goHome;
    document.querySelectorAll("[data-mcm]").forEach(b => b.onclick = () => { const m = L[+b.dataset.mcm]; if (m.fait) return toast("Visite déjà envoyée."); S.mcSel = m; S.mcD = mcDraft(m); S.mcPh = []; render(); scrollTo(0, 0);
      api("photos", null, "token=" + S.mcD.token).then(r => { S.mcPh = r.photos; if (S.view === "mc") render(); }).catch(() => {}); });
    return;
  }
  const m = S.mcSel, d = S.mcD, ph = S.mcPh || [], cat = (C.catalogue || {})[m.marque_id] || [], base = (C.refs || {})[m.devis_id] || [], mx = C.max_photos || 8;
  // Liste à relever de l'opération, plus les produits du catalogue scannés hors liste pendant la visite.
  const refs = base.concat(cat.filter(r => (d.plus || []).includes(r.id) && !base.some(b => b.id === r.id)));
  const phBlock = (slot, titre, aide) => { const P = ph.filter(p => p.slot === slot); return `<div class="card"><h2>${titre}</h2><div class="muted">${aide}</div>
    <div class="photos">${P.map(p => `<div class="ph"><img src="${p.url}" alt=""><button class="ghost sm" data-mcdel="${p.id}">Retirer</button></div>`).join("")}</div>
    ${P.length < mx ? `<label class="btn">${P.length ? "Ajouter" : "Prendre"} des photos<input type="file" data-mcph="${slot}" accept="image/*" capture="environment" multiple hidden></label>` : ""}</div>`; };
  const seg = (k, opts, v) => `<div class="seg mc-seg">${opts.map(([val, lab]) => `<button type="button" data-mcseg="${k}" data-v="${val}" aria-pressed="${v === val}">${esc(lab)}</button>`).join("")}</div>`;
  const R = r => d.refs[r.id] || (d.refs[r.id] = {statut:"", prix:"", facings:"", etiquette:1, promo:0, promo_txt:""});
  const yes = (k, lab) => `<label class="ck"><input type="checkbox" data-mcb="${k}" ${+d[k] ? "checked" : ""}><span>${lab}</span></label>`;
  $("#view").innerHTML = `<div class="card"><button class="ghost" id="mc-list">‹ Visites</button><h2 style="margin-top:10px">${esc(m.magasin)}</h2>
      <div class="muted">${esc(pLong(m.date))} · ${esc(m.operation)}${m.tournee ? ` · point ${m.ordre} sur ${m.nb}` : ""}</div>
      ${d.arrivee ? `<div class="hint" style="margin-top:8px">Arrivée notée à <b>${esc(d.arrivee)}</b></div>` : `<div class="btns"><button class="primary" id="mc-arr">J'arrive dans le magasin</button></div>`}</div>
    ${phBlock("avant", "1. Photos avant", "Le rayon ou le meuble tel que vous le trouvez, avant toute intervention : vue d'ensemble puis détails.")}
    ${refs.length ? `<div class="card"><h2>2. Relevé des références</h2><div class="muted">Scannez le gencod de chaque produit en rayon : la référence s'ouvre, vous notez le prix et les facings. Ou remplissez la liste à la main.</div>
      ${(() => { const fait = refs.filter(r => (d.refs[r.id] || {}).statut).length; return `<div class="mc-scanbar"><button type="button" class="primary" id="mc-scan">📷 Scanner les gencods</button><span class="hint"><b>${fait}</b> / ${refs.length} référence${refs.length > 1 ? "s" : ""} relevée${fait > 1 ? "s" : ""}${d.inconnus && d.inconnus.length ? ` · ${d.inconnus.length} gencod${d.inconnus.length > 1 ? "s" : ""} hors liste noté${d.inconnus.length > 1 ? "s" : ""}` : ""}</span></div>
        ${fait && fait < refs.length ? `<div class="hint" style="margin-top:6px">Les ${refs.length - fait} non relevées : <button type="button" class="linkbtn" data-mcreste="rupture">en rupture</button> · <button type="button" class="linkbtn" data-mcreste="absent">non référencées</button></div>` : ""}`; })()}
      ${refs.map(r => { const x = R(r); return `<div class="mc-ref"><div><b>${esc(r.nom)}</b><div class="hint">${esc([r.gamme, r.ean].filter(Boolean).join(" · "))}${r.prix ? " · PVP " + String(r.prix).replace(".", ",") + " €" : ""}${base.some(b => b.id === r.id) ? "" : " · hors liste"}</div></div>
        ${seg("st:" + r.id, Object.entries(C.statuts || {}), x.statut)}
        ${x.statut && x.statut !== "absent" ? `<div class="row"><label>Prix affiché (€)<input data-mcr="${r.id}" data-k="prix" inputmode="decimal" value="${esc(x.prix)}"></label><label>Facings<input data-mcr="${r.id}" data-k="facings" inputmode="numeric" value="${esc(x.facings)}"></label></div>
          <label class="ck"><input type="checkbox" data-mcrb="${r.id}" data-k="etiquette" ${+x.etiquette ? "checked" : ""}><span>Étiquette prix présente</span></label>
          <label class="ck"><input type="checkbox" data-mcrb="${r.id}" data-k="promo" ${+x.promo ? "checked" : ""}><span>En promotion</span></label>${+x.promo ? `<input data-mcr="${r.id}" data-k="promo_txt" placeholder="Mécanique : -30 %, 2+1, coffret…" value="${esc(x.promo_txt)}">` : ""}` : ""}</div>`; }).join("")}</div>`
      : `<div class="card"><h2>2. Relevé des références</h2><div class="hint">Aucune référence à relever pour cette marque.</div></div>`}
    <div class="card"><h2>3. Rayon et linéaire</h2>
      <div class="row"><label>Linéaire de la marque (cm)<input data-mc="lineaire_marque" inputmode="decimal" value="${esc(d.lineaire_marque)}"></label><label>Linéaire total du rayon (cm)<input data-mc="lineaire_total" inputmode="decimal" value="${esc(d.lineaire_total)}"></label></div>
      <div class="row"><label>Tablettes occupées<input data-mc="tablettes" inputmode="numeric" value="${esc(d.tablettes)}"></label><label>Hauteur<select data-mc="hauteur"><option value="">Choisir…</option>${(C.hauteurs || []).map(h => `<option ${d.hauteur === h ? "selected" : ""}>${esc(h)}</option>`).join("")}</select></label></div>
      <label>Emplacement<select data-mc="emplacement"><option value="">Choisir…</option>${(C.emplacements || []).map(h => `<option ${d.emplacement === h ? "selected" : ""}>${esc(h)}</option>`).join("")}</select></label>
      <label>Stock en réserve<select data-mc="reserve"><option value="">Choisir…</option>${Object.entries(C.reserve || {}).map(([k, l]) => `<option value="${k}" ${d.reserve === k ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></label></div>
    <div class="card"><h2>4. Promotion et PLV</h2>${yes("promo_en_place", "Promotion de la marque en place")}${+d.promo_en_place ? `<input data-mc="promo_txt" placeholder="Laquelle ? (mécanique, support)" value="${esc(d.promo_txt)}">` : ""}
      ${yes("plv_presente", "PLV présente à l'arrivée")}${yes("plv_posee", "PLV posée ou remise en place pendant la visite")}</div>
    <div class="card"><h2>5. Travaux réalisés</h2><div class="mc-ck">${(C.taches || []).map(t => `<label class="ck"><input type="checkbox" data-mct="${esc(t)}" ${d.taches.includes(t) ? "checked" : ""}><span>${esc(t)}</span></label>`).join("")}</div></div>
    ${phBlock("apres", "6. Photos après", "Le même cadrage qu'avant, une fois le travail terminé : c'est ce que la marque compare.")}
    <div class="card"><h2>7. Concurrence</h2><label>Ce que font les marques concurrentes <span class="hint">facultatif</span><textarea data-mc="concurrence" rows="2" placeholder="Promotions, nouveautés, PLV, animations…">${esc(d.concurrence)}</textarea></label>
      <div class="photos">${ph.filter(p => p.slot === "conc").map(p => `<div class="ph"><img src="${p.url}" alt=""><button class="ghost sm" data-mcdel="${p.id}">Retirer</button></div>`).join("")}</div>
      <label class="btn">Photo de la concurrence<input type="file" data-mcph="conc" accept="image/*" capture="environment" multiple hidden></label></div>
    <div class="card"><h2>8. En magasin</h2><div class="row"><label>Personne rencontrée<input data-mc="interlocuteur" value="${esc(d.interlocuteur)}" placeholder="Prénom et nom"></label><label>Fonction<input data-mc="interlocuteur_fonction" value="${esc(d.interlocuteur_fonction)}" placeholder="Chef de rayon, directeur…"></label></div>
      <label>Commentaire <span class="hint">facultatif</span><textarea data-mc="commentaire" rows="3" placeholder="Difficultés, demandes du magasin, commandes à prévoir…">${esc(d.commentaire)}</textarea></label></div>
    <div class="sticky-submit"><button class="primary" id="mc-go">Envoyer la visite</button></div>`;
  $("#mc-list").onclick = () => { S.mcSel = null; render(); };
  if ($("#mc-arr")) $("#mc-arr").onclick = () => { d.arrivee = hNow(); mcSave(); render(); };
  if ($("#mc-scan")) $("#mc-scan").onclick = () => { if (!d.arrivee) { d.arrivee = hNow(); mcSave(); } mcScanner(refs, d, cat); };
  document.querySelectorAll("[data-mcreste]").forEach(b => b.onclick = () => { base.forEach(r => { const x = R(r); if (!x.statut) x.statut = b.dataset.mcreste; }); mcSave(); render(); });
  document.querySelectorAll("[data-mc]").forEach(i => i.oninput = i.onchange = () => { d[i.dataset.mc] = i.value; mcSave(); });
  document.querySelectorAll("[data-mcb]").forEach(i => i.onchange = () => { d[i.dataset.mcb] = i.checked ? 1 : 0; mcSave(); if (i.dataset.mcb === "promo_en_place") render(); });
  document.querySelectorAll("[data-mct]").forEach(i => i.onchange = () => { const t = i.dataset.mct; d.taches = d.taches.filter(x => x !== t); if (i.checked) d.taches.push(t); mcSave(); });
  document.querySelectorAll("[data-mcseg]").forEach(b => b.onclick = () => { const id = b.dataset.mcseg.split(":")[1]; d.refs[id] = Object.assign(d.refs[id] || {}, {statut:b.dataset.v}); mcSave(); render(); });
  document.querySelectorAll("[data-mcr]").forEach(i => i.oninput = () => { d.refs[i.dataset.mcr][i.dataset.k] = i.value; mcSave(); });
  document.querySelectorAll("[data-mcrb]").forEach(i => i.onchange = () => { d.refs[i.dataset.mcrb][i.dataset.k] = i.checked ? 1 : 0; mcSave(); if (i.dataset.k === "promo") render(); });
  document.querySelectorAll("[data-mcdel]").forEach(b => b.onclick = async () => { try { S.mcPh = (await api("delete_photo", {id:+b.dataset.mcdel, token:d.token})).photos; render(); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-mcph]").forEach(inp => inp.onchange = async e => { const slot = inp.dataset.mcph, n = ph.filter(p => p.slot === slot).length, files = [...e.target.files].slice(0, mx - n);
    if (slot === "avant" && !d.arrivee) { d.arrivee = hNow(); mcSave(); }
    toast("Envoi de " + files.length + " photo" + (files.length > 1 ? "s" : "") + "…");
    for (const f of files) { try { const fd = new FormData(); fd.append("photo", await shrink(f), "photo.jpg"); fd.append("token", d.token); fd.append("slot", slot); S.mcPh = (await api("upload_photo", fd)).photos; } catch(x) { toast(x.message); } }
    render(); });
  $("#mc-go").onclick = async () => { const b = $("#mc-go"); b.disabled = true;
    try { await api("mc_submit", {token:d.token, devis_id:m.devis_id, date:m.date, magasin:m.magasin, arrivee:d.arrivee, depart:hNow(), data:Object.assign({}, d, {refs:Object.entries(d.refs).map(([id, x]) => Object.assign({id:+id}, x))})});
      try { localStorage.removeItem(MCK(m)); } catch(e) {} m.fait = true; S.mcSel = null; toast("Visite envoyée, merci !"); const next = (S.mcM || []).find(x => !x.fait && x.date === m.date && x.devis_id === m.devis_id); render(); if (next) toast("Prochain point : " + next.magasin); }
    catch(e) { toast(e.message); b.disabled = false; } };
}

/* ---------- Scanette de gencods (caméra du téléphone ou scanette Bluetooth) ---------- */
const eanNorm = c => { c = String(c || "").replace(/\D/g, ""); return c.length === 12 ? "0" + c : c; };
const SC = { stop:null, last:"", lastT:0, pause:false };
function loadZXing(){ return window.ZXing ? Promise.resolve() : new Promise((ok, ko) => { const s = document.createElement("script"); s.src = "assets/zxing.min.js"; s.onload = ok; s.onerror = () => ko(new Error("Lecteur de codes-barres indisponible.")); document.head.appendChild(s); }); }
async function mcScanner(refs, d, cat){
  const ov = document.createElement("div"); ov.className = "scan"; ov.innerHTML = `<div class="scan-top"><b>Scanner les gencods</b><span id="scan-n" class="scan-n"></span><button type="button" id="scan-x">Terminer</button></div>
    <div class="scan-v"><video id="scan-v" playsinline muted autoplay></video><div class="scan-cadre"></div><div class="scan-msg" id="scan-msg">Visez le code-barres du produit</div></div>
    <div id="scan-card"></div><form id="scan-f" class="scan-f"><input id="scan-in" inputmode="numeric" autocomplete="off" placeholder="Saisir le gencod ou scanette Bluetooth"><button class="ghost">OK</button></form>`;
  document.body.appendChild(ov); document.body.style.overflow = "hidden";
  const byEan = {}, byCat = {}; refs.forEach(r => { if (r.ean) byEan[eanNorm(r.ean)] = r; }); (cat || []).forEach(r => { if (r.ean) byCat[eanNorm(r.ean)] = r; });
  const compte = () => { $("#scan-n").textContent = refs.filter(r => (d.refs[r.id] || {}).statut).length + " / " + refs.length; }; compte();
  const fermer = () => { try { SC.stop && SC.stop(); } catch(e) {} SC.stop = null; ov.remove(); document.body.style.overflow = ""; render(); };
  $("#scan-x").onclick = fermer;
  const carte = html => { $("#scan-card").innerHTML = html; SC.pause = !!html; };
  const code = raw => { const c = eanNorm(raw); if (!c || SC.pause) return; const t = Date.now(); if (c === SC.last && t - SC.lastT < 2500) return; SC.last = c; SC.lastT = t;
    try { navigator.vibrate && navigator.vibrate(60); } catch(e) {}
    let r = byEan[c];
    // Produit du catalogue de la marque, hors de la liste de l'opération : ajouté au relevé de cette visite.
    if (!r && byCat[c]) { r = byCat[c]; byEan[c] = r; refs.push(r); d.plus = d.plus || []; if (!d.plus.includes(r.id)) d.plus.push(r.id); toast("Hors liste : ajoutée au relevé"); }
    if (!r) { carte(`<div class="scan-c bad"><b>Gencod inconnu</b><div class="hint">${esc(c)} · inconnu du catalogue de la marque.</div>
        <div class="btns"><button type="button" class="ghost" id="sc-note">Le noter quand même</button><button type="button" class="primary" id="sc-next">Continuer</button></div></div>`);
      $("#sc-note").onclick = () => { d.inconnus = d.inconnus || []; if (!d.inconnus.includes(c)) d.inconnus.push(c); mcSave(); carte(""); toast("Gencod noté"); };
      $("#sc-next").onclick = () => carte(""); return; }
    const x = d.refs[r.id] || (d.refs[r.id] = {statut:"", prix:"", facings:"", etiquette:1, promo:0, promo_txt:""}); if (!x.statut) x.statut = "present"; if (x.facings === "") x.facings = 1;
    const vue = () => carte(`<div class="scan-c"><div class="scan-ok">✓ ${esc(r.nom)}</div><div class="hint">${esc([r.gamme, r.prix ? "PVP " + String(r.prix).replace(".", ",") + " €" : ""].filter(Boolean).join(" · "))}</div>
      <div class="seg mc-seg">${[["present", "En rayon"], ["rupture", "Rupture"], ["absent", "Non référencé"]].map(([v, l]) => `<button type="button" data-scst="${v}" aria-pressed="${x.statut === v}">${l}</button>`).join("")}</div>
      ${x.statut !== "absent" ? `<div class="scan-row"><label>Prix affiché<input id="sc-px" inputmode="decimal" value="${esc(x.prix)}" placeholder="€"></label>${r.prix ? `<button type="button" class="ghost sm" id="sc-pc">= ${String(r.prix).replace(".", ",")} €</button>` : ""}</div>
        <div class="scan-row"><span>Facings</span><button type="button" class="ghost sm" data-scf="-1">−</button><b id="sc-f">${x.facings}</b><button type="button" class="ghost sm" data-scf="1">+</button></div>
        <label class="ck"><input type="checkbox" id="sc-et" ${+x.etiquette ? "checked" : ""}><span>Étiquette prix présente</span></label><label class="ck"><input type="checkbox" id="sc-pr" ${+x.promo ? "checked" : ""}><span>En promotion</span></label>` : ""}
      <button type="button" class="primary" id="sc-ok" style="width:100%;margin-top:8px">Valider · produit suivant</button></div>`) || lier();
    const lire = () => { if ($("#sc-px")) { x.prix = $("#sc-px").value; x.etiquette = $("#sc-et").checked ? 1 : 0; x.promo = $("#sc-pr").checked ? 1 : 0; } };
    const lier = () => { document.querySelectorAll("[data-scst]").forEach(b => b.onclick = () => { lire(); x.statut = b.dataset.scst; vue(); });
      if ($("#sc-pc")) $("#sc-pc").onclick = () => { $("#sc-px").value = String(r.prix).replace(".", ","); };
      document.querySelectorAll("[data-scf]").forEach(b => b.onclick = () => { x.facings = Math.max(0, (+x.facings || 0) + +b.dataset.scf); $("#sc-f").textContent = x.facings; });
      $("#sc-ok").onclick = () => { lire(); d.scannes = (+d.scannes || 0) + 1; mcSave(); compte(); carte(""); $("#scan-msg").textContent = "Produit suivant…"; };
      if ($("#sc-px")) setTimeout(() => $("#sc-px") && !$("#sc-px").value && $("#sc-px").focus(), 80); };
    vue(); lier(); };
  $("#scan-f").onsubmit = e => { e.preventDefault(); const v = $("#scan-in").value; $("#scan-in").value = ""; SC.last = ""; code(v); };
  // Caméra : détecteur natif du téléphone si disponible, sinon lecteur intégré (iPhone).
  const video = $("#scan-v");
  try {
    let natif = null; if ("BarcodeDetector" in window) { try { const f = await BarcodeDetector.getSupportedFormats(); if (f.includes("ean_13")) natif = new BarcodeDetector({formats:["ean_13", "ean_8", "upc_a"].filter(x => f.includes(x))}); } catch(e) {} }
    if (natif) {
      const st = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:"environment"}, width:{ideal:1280}}, audio:false}); video.srcObject = st; await video.play();
      let on = true; SC.stop = () => { on = false; st.getTracks().forEach(t => t.stop()); };
      const boucle = async () => { if (!on) return; if (!SC.pause && video.readyState >= 2) { try { const L = await natif.detect(video); if (L.length) code(L[0].rawValue); } catch(e) {} } setTimeout(boucle, 180); }; boucle();
    } else {
      await loadZXing(); const H = new Map(); H.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, [ZXing.BarcodeFormat.EAN_13, ZXing.BarcodeFormat.EAN_8, ZXing.BarcodeFormat.UPC_A]); H.set(ZXing.DecodeHintType.TRY_HARDER, true);
      const rd = new ZXing.BrowserMultiFormatReader(H, 200); SC.stop = () => rd.reset();
      await rd.decodeFromConstraints({video:{facingMode:{ideal:"environment"}, width:{ideal:1280}}, audio:false}, video, res => { if (res) code(res.getText()); });
    }
  } catch(e) { $("#scan-msg").textContent = "Caméra indisponible : autorisez la caméra pour ce site, ou saisissez le gencod ci-dessous (une scanette Bluetooth marche aussi)."; $("#scan-msg").classList.add("err"); setTimeout(() => $("#scan-in") && $("#scan-in").focus(), 100); }
}

/* ---------- Mes factures (auto-entrepreneurs) ---------- */
async function openFae(){ S.view = "fae"; try { S.fae = await api("mes_factures"); } catch(e) { return toast(e.message); } render(); scrollTo(0, 0); }
function vFae(){
  const R = S.fae || {factures:[]}, eur = v => (+v).toLocaleString("fr-FR", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €", pill = {recue:"", validee:"p-ok", payee:"p-ok", refusee:"p-bad"};
  const mois = today().slice(0, 7);
  $("#view").innerHTML = `<div class="btns"><button class="ghost" id="back">‹ Accueil</button></div>
    <div class="card accent"><h2>Mes factures</h2><div class="muted">Déposez ici vos factures d'auto-entrepreneur (PDF ou photo). ${AG} les vérifie et vous prévient à chaque étape, jusqu'au paiement.</div></div>
    <div class="card"><h2>Nouvelle facture</h2>
      <div class="row"><label>N° de facture<input id="fa-n" placeholder="Ex. 2026-014"></label><label>Montant (€)<input id="fa-m" inputmode="decimal" placeholder="Ex. 450,00"></label></div>
      <div class="row"><label>Mois facturé<input type="month" id="fa-p" value="${mois}"></label><label>Opération <span class="hint">facultatif</span><select id="fa-o"><option value="">—</option>${(R.operations || []).map(o => `<option value="${o.devis_id}">${esc(o.objet || "Opération " + (o.numero || ""))}</option>`).join("")}</select></label></div>
      <label>Commentaire <span class="hint">facultatif</span><input id="fa-c"></label>
      <label class="btn" style="text-align:center">Choisir la facture (PDF ou photo)<input type="file" id="fa-f" accept="application/pdf,image/*" hidden></label><div class="hint" id="fa-fn"></div>
      <button class="primary" id="fa-go">Envoyer la facture</button></div>
    <div class="card"><h2>Envoyées</h2>${R.factures.length ? R.factures.map(f => `<div class="rel-l"><div><b>${esc(f.numero || "Facture")}</b> · ${eur(f.montant)} <span class="pill ${pill[f.statut] || ""}">${esc(f.statut_txt)}</span>
        <div class="muted">${esc(f.periode)}${f.objet ? " · " + esc(f.objet) : ""}${f.motif ? " · " + esc(f.motif) : ""}</div></div>
        <div><a class="btn ghost sm" href="${f.url}" target="_blank" rel="noopener">Voir</a>${f.statut === "recue" ? ` <button class="ghost sm" data-fadel="${f.id}">Retirer</button>` : ""}</div></div>`).join("") : `<div class="empty">Aucune facture pour l'instant.</div>`}</div>`;
  $("#back").onclick = goHome;
  $("#fa-f").onchange = () => { const f = $("#fa-f").files[0]; $("#fa-fn").textContent = f ? f.name : ""; };
  document.querySelectorAll("[data-fadel]").forEach(b => b.onclick = async () => { try { S.fae.factures = (await api("facture_ae_suppr", {id:+b.dataset.fadel})).factures; vFae(); } catch(e) { toast(e.message); } });
  $("#fa-go").onclick = async () => { const f = $("#fa-f").files[0]; if (!f) return toast("Joignez la facture.");
    const fd = new FormData(); fd.append("numero", $("#fa-n").value); fd.append("montant", $("#fa-m").value); fd.append("periode", $("#fa-p").value); fd.append("devis_id", $("#fa-o").value); fd.append("commentaire", $("#fa-c").value);
    fd.append("fichier", f.type.startsWith("image/") ? await shrink(f) : f, f.type.startsWith("image/") ? "facture.jpg" : f.name);
    const b = $("#fa-go"); b.disabled = true; try { S.fae.factures = (await api("facture_ae_ajouter", fd)).factures; toast("Facture envoyée"); vFae(); } catch(e) { toast(e.message); b.disabled = false; } };
}
