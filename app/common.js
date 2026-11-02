const $ = s => document.querySelector(s);
const esc = s => String(s == null ? "" : s).replace(/[&<>"]/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const SYM = {EUR:"€"};
const money = (v, d) => (v == null || v === "") ? "" : Math.round(+v).toLocaleString("fr-FR") + " " + (SYM[d] || d || "€");
const fmtD = s => s ? new Date(s + (s.length === 10 ? "T12:00:00" : "")).toLocaleDateString("fr-FR", {weekday:"short", day:"2-digit", month:"2-digit", year:"numeric"}) : "";
function toast(m){ const t=$("#toast"); t.textContent=m; t.hidden=false; clearTimeout(toast.h); toast.h=setTimeout(()=>t.hidden=true,Math.max(3200,m.length*60)); }
/** Session de la direction fermée (12 h ou 60 min d'inactivité) : on prévient et on revient à l'écran de connexion. */
function sessionExpiree(r){ if (r && r.headers && r.headers.get("X-Amaya-Session") === "expiree" && !sessionExpiree.vu) { sessionExpiree.vu = true; try { toast("Session expirée après inactivité : reconnectez-vous."); } catch(e) {} setTimeout(() => location.reload(), 1800); } }
async function api(action, body, query){
  const url = "api.php?action=" + action + (query ? "&" + query : "");
  const isForm = body instanceof FormData;
  let rem = ""; try { rem = localStorage.getItem("amy_rem") || ""; } catch(e) {}
  const h = Object.assign(isForm ? {"X-Requested-With":"amaya"} : body ? {"Content-Type":"application/json","X-Requested-With":"amaya"} : {}, rem ? {"X-Amaya-Rem":rem} : {});
  const opt = body ? {method:"POST", credentials:"same-origin", headers:h, body: isForm ? body : JSON.stringify(body)} : {credentials:"same-origin", headers:h};
  let r; try { r = await fetch(url, opt); } catch(e) { throw new Error("Pas de connexion internet. Vos saisies sont gardées sur le téléphone, réessayez."); }
  sessionExpiree(r);
  const txt = await r.text(); let data = {}; try { data = JSON.parse(txt); } catch(e) {}
  if (!r.ok) { const raw = txt.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim().slice(0, 160);
    const err = new Error(data.error || "Erreur " + r.status + (raw ? " : " + raw : "")); err.status = r.status; throw err; }
  return data;
}
/** Réduit une photo à 1600 px (JPEG) avant l'envoi : plus rapide sur réseau mobile. */
async function shrink(file){
  try {
    const bmp = await createImageBitmap(file, {imageOrientation:"from-image"});
    const r = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
    const c = document.createElement("canvas"); c.width = Math.round(bmp.width * r); c.height = Math.round(bmp.height * r);
    c.getContext("2d").drawImage(bmp, 0, 0, c.width, c.height);
    return await new Promise(res => c.toBlob(b => res(b || file), "image/jpeg", 0.85));
  } catch(e) { return file; }
}

/* ---------- Installation de l'application (iPhone et Android) ---------- */
let installEvt = null;
addEventListener("beforeinstallprompt", e => { e.preventDefault(); installEvt = e; });
const isStandalone = () => matchMedia("(display-mode: standalone)").matches || !!navigator.standalone;
const isIOS = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
/** Un seul bouton : Android installe directement ; iPhone affiche une flèche vers le bouton Partager de Safari. */
async function installApp(){
  if (isStandalone()) return installSheet("L'application est déjà installée", ("Ouvrez-la depuis l'icône " + AG + " sur votre écran d'accueil."));
  if (installEvt) { installEvt.prompt(); await installEvt.userChoice; installEvt = null; return; }
  const ua = navigator.userAgent;
  const inApp = /FBAN|FBAV|Instagram|Line\/|WhatsApp|Snapchat|LinkedInApp|GSA\//i.test(ua);
  if (isIOS()) {
    if (inApp) return installSheet("Ouvrez ce lien dans Safari ou Chrome", "WhatsApp ou Facebook ne permettent pas d'ajouter l'icône. Touchez les <b>…</b> ou la boussole, choisissez « Ouvrir dans le navigateur », puis touchez de nouveau Installer.");
    const chrome = /CriOS/i.test(ua), autre = /FxiOS|EdgiOS/i.test(ua);
    const step1 = chrome
      ? 'Touchez le bouton Partager</div><div style="font-size:14px;color:#3A302A">C&#39;est le carré avec une flèche vers le haut, <b>en haut à droite, dans la barre d&#39;adresse</b>. Pas visible ? Touchez <b>⋯</b> en bas à droite puis « Partager ».</div>'
      : autre
      ? 'Ouvrez le menu puis « Partager »</div><div style="font-size:14px;color:#3A302A">Touchez <b>☰</b> ou <b>⋯</b> en bas de l&#39;écran, puis « Partager ».</div>'
      : 'En bas de l&#39;écran, touchez ce bouton</div><img src="assets/ios-etape1.png" alt="" style="width:100%;border-radius:12px;display:block">';
    return installSheet(("Mettre l'icône " + AG + " sur votre iPhone"), `<div style="display:flex;flex-direction:column;gap:16px"><div style="display:flex;flex-direction:column;gap:7px;text-align:left"><div style="font-weight:600;font-size:16px;display:flex;align-items:center;gap:8px"><span style="display:inline-flex;flex:none;width:24px;height:24px;border-radius:50%;background:var(--terra);color:#fff;align-items:center;justify-content:center;font-size:13px">1</span>${step1}</div><div style="display:flex;flex-direction:column;gap:7px;text-align:left"><div style="font-weight:600;font-size:16px;display:flex;align-items:center;gap:8px"><span style="display:inline-flex;flex:none;width:24px;height:24px;border-radius:50%;background:var(--terra);color:#fff;align-items:center;justify-content:center;font-size:13px">2</span>Touchez « Sur l&#39;écran d&#39;accueil »</div><img src="assets/ios-etape2.png" alt="" style="width:100%;border-radius:12px;display:block"><div style="font-size:12px;color:#8E8E93">Elle est dans la liste sous les ronds gris. Pas visible ? Faites défiler vers le bas.</div></div><div style="display:flex;flex-direction:column;gap:7px;text-align:left"><div style="font-weight:600;font-size:16px;display:flex;align-items:center;gap:8px"><span style="display:inline-flex;flex:none;width:24px;height:24px;border-radius:50%;background:var(--terra);color:#fff;align-items:center;justify-content:center;font-size:13px">3</span>Touchez « Ajouter » en haut à droite</div><img src="assets/ios-etape3.png" alt="" style="width:100%;border-radius:12px;display:block"><div style="font-size:12px;color:#8E8E93">Laissez « Ouvrir comme app web » activé.</div></div><div style="color:#7A6D62;font-size:14px">L&#39;icône ${AG} apparaît sur votre écran.</div></div>`, true);
  }
  if (inApp) return installSheet("Ouvrez ce lien dans Chrome", "Touchez le menu ⋮ puis « Ouvrir dans Chrome », puis touchez de nouveau Installer.");
  return installSheet(("Ajouter l'icône " + AG + " sur votre téléphone"), "Touchez le menu <b>⋮</b> en haut à droite de Chrome, puis <b>« Installer l'application »</b> ou <b>« Ajouter à l'écran d'accueil »</b>.");
}
function installSheet(title, html, arrowDown){
  document.getElementById("amy-sheet")?.remove();
  const d = document.createElement("div"); d.id = "amy-sheet";
  d.style.cssText = "position:fixed;inset:0;z-index:999;background:rgba(26,21,18,.55);display:flex;align-items:center;justify-content:center;padding:12px;cursor:pointer";
  d.innerHTML = `<div style="background:#fff;border-radius:18px;max-width:420px;width:100%;max-height:calc(100vh - 24px);max-height:calc(100dvh - 24px);overflow-y:auto;-webkit-overflow-scrolling:touch;padding:18px 18px 16px;text-align:center;color:#1A1512;box-shadow:0 20px 50px rgba(0,0,0,.3);cursor:default">
    ${arrowDown ? "" : `<img src="assets/icon-180.png" alt="" style="width:56px;height:56px;border-radius:13px">`}
    <div style="position:relative"><button type="button" class="amy-close" aria-label="Fermer" style="position:absolute;top:-6px;right:-6px;width:36px;height:36px;border-radius:50%;border:0;background:#F2F2F7;color:#1A1512;font-size:18px;line-height:1;cursor:pointer;-webkit-appearance:none">✕</button>
    <div style="font-size:19px;font-weight:600;margin:${arrowDown ? "0" : "10px"} 0 12px;padding:0 34px">${title}</div></div>
    <div style="font-size:15px;line-height:1.5;color:#3A302A">${html}</div>
    <button type="button" class="amy-close" style="display:block;width:100%;margin-top:16px;padding:14px;border-radius:12px;border:0;background:#1A1512;color:#fff;font:inherit;font-weight:600;font-size:16px;cursor:pointer;-webkit-appearance:none">J'ai compris</button>
</div>`;
  document.body.appendChild(d);
  const close = e => { if (e) { e.preventDefault(); e.stopPropagation(); } d.remove(); };
  d.querySelectorAll(".amy-close").forEach(b => { b.addEventListener("click", close); b.addEventListener("touchend", close); });
  d.addEventListener("click", e => { if (e.target === d) close(e); });
}
document.addEventListener("click", e => { const t = e.target.closest("[data-install]"); if (t) { e.preventDefault(); installApp(); } });

/** Question Oui / Non (sans boîte de dialogue du navigateur). Renvoie une promesse : true si l'on confirme. */
function askSheet(title, html, yes = "Oui", no = "Non"){
  return new Promise(res => {
    document.getElementById("amy-ask")?.remove();
    const d = document.createElement("div"); d.id = "amy-ask";
    d.style.cssText = "position:fixed;inset:0;z-index:200;background:rgba(26,21,18,.55);display:flex;align-items:center;justify-content:center;padding:16px";
    d.innerHTML = `<div style="background:#fff;border-radius:18px;max-width:400px;width:100%;padding:22px 20px;text-align:center;color:#1A1512">
      <div style="font-size:18px;font-weight:600;margin-bottom:8px">${title}</div><div style="color:#7A6D62;margin-bottom:16px;line-height:1.5">${html}</div>
      <div style="display:flex;gap:10px"><button type="button" class="ghost" data-no style="flex:1">${no}</button><button type="button" class="primary" data-yes style="flex:1">${yes}</button></div></div>`;
    d.onclick = e => { if (e.target === d || e.target.closest("[data-no]")) { d.remove(); res(false); } else if (e.target.closest("[data-yes]")) { d.remove(); res(true); } };
    document.body.appendChild(d);
  });
}
const CA_ALERTE = 9999;
const euros = v => (+v).toLocaleString("fr-FR", {maximumFractionDigits:2}) + " €";

/* ---------- Contrat de travail : nationalité et n° de sécurité sociale sans erreur ---------- */
const NATIONALITES = ["Française", "Algérienne", "Allemande", "Américaine", "Belge", "Brésilienne", "Britannique", "Camerounaise", "Canadienne", "Chinoise", "Comorienne", "Congolaise", "Cubaine", "Dominicaine",
  "Espagnole", "Haïtienne", "Indienne", "Italienne", "Ivoirienne", "Japonaise", "Libanaise", "Luxembourgeoise", "Malgache", "Malienne", "Marocaine", "Mauricienne", "Néerlandaise", "Polonaise",
  "Portugaise", "Roumaine", "Russe", "Sénégalaise", "Seychelloise", "Suisse", "Tunisienne", "Turque", "Ukrainienne", "Vénézuélienne", "Vietnamienne"];
/** Liste déroulante + champ libre si « Autre ». */
function natField(v, attr){
  const known = !v || NATIONALITES.includes(v);
  return `<label>Nationalité<select ${attr}="sel"><option value="">Choisir…</option>${NATIONALITES.map(n => `<option ${v === n ? "selected" : ""}>${n}</option>`).join("")}<option value="__autre" ${known ? "" : "selected"}>Autre…</option></select>
    <input ${attr}="autre" placeholder="Précisez la nationalité" value="${known ? "" : esc(v)}" ${known ? "hidden" : ""} style="margin-top:6px"></label>`;
}
function natBind(attr, set){
  const s = document.querySelector(`[${attr}="sel"]`), a = document.querySelector(`[${attr}="autre"]`); if (!s) return;
  const up = () => { a.hidden = s.value !== "__autre"; set(s.value === "__autre" ? a.value.trim() : s.value); };
  s.onchange = up; a.oninput = up;
}
const secuFmt = v => { const r = String(v || "").toUpperCase().replace(/[^0-9AB]/g, "").slice(0, 15); let o = "", i = 0; for (const n of [1, 2, 2, 2, 3, 3, 2]) { if (i >= r.length) break; o += (o ? " " : "") + r.slice(i, i + n); i += n; } return o; };
/** Contrôle du n° : longueur, clé, sexe (civilité) et année/mois de naissance. */
function secuCheck(v, civ, naiss){
  const r = String(v || "").toUpperCase().replace(/\s/g, ""); if (!r) return {ok:true, msg:""};
  if (r.length < 15) return {ok:false, msg:r.length + " caractères sur 15 (13 + la clé de 2 chiffres)"};
  const m = /^([12378])(\d{2})(\d{2})(\d{2}|2A|2B)(\d{3})(\d{3})(\d{2})$/.exec(r); if (!m) return {ok:false, msg:"Format incorrect : le numéro commence par 1 ou 2, puis l'année et le mois de naissance."};
  const n = BigInt(r.slice(0, 13).replace("2A", "19").replace("2B", "18")); if (97n - n % 97n !== BigInt(+m[7])) return {ok:false, msg:"La clé (2 derniers chiffres) ne correspond pas : vérifiez le numéro."};
  if (civ === "Mme" && m[1] === "1" || civ === "M." && m[1] === "2") return {ok:false, msg:"Le 1er chiffre (" + m[1] + ") ne correspond pas à la civilité."};
  if (naiss && /^\d{4}-\d{2}/.test(naiss) && (naiss.slice(2, 4) !== m[2] || (+m[3] <= 12 && naiss.slice(5, 7) !== m[3]))) return {ok:false, msg:"L'année ou le mois ne correspond pas à la date de naissance."};
  return {ok:true, msg:"Numéro valide ✓"};
}
function secuField(v, attr){ return `<label>N° de sécurité sociale<input class="secu-box" ${attr} inputmode="numeric" autocomplete="off" maxlength="21" placeholder="2 95 08 75 115 215 84" value="${esc(secuFmt(v))}"><span class="secu-msg hint"></span></label>`; }
/** Mise en forme pendant la saisie et message de contrôle. */
function secuBind(attr, getCiv, getNaiss, set){
  const i = document.querySelector(`[${attr}]`); if (!i) return; const msg = i.parentNode.querySelector(".secu-msg");
  const up = () => { const p = i.selectionStart === i.value.length; i.value = secuFmt(i.value); if (p) i.setSelectionRange(i.value.length, i.value.length);
    const c = secuCheck(i.value, getCiv(), getNaiss()); msg.textContent = c.msg; msg.style.color = c.ok ? "#2F6B45" : "var(--terra)"; i.classList.toggle("bad", !c.ok); set(i.value); };
  i.oninput = up; up(); return up;
}
