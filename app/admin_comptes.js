// Administration : listes de destinataires, comptes administrateurs, mon compte, objectifs.
const can = d => { const m = A.L.moi || {}, x = m.droits || []; return d === "lecture" || (d === "principal" ? !!m.principal : x.includes("tout") || x.includes(d)); };
const eurA = v => (v == null || v === "" || isNaN(v)) ? "" : Math.round(+v).toLocaleString("fr-FR") + " €";
// Semaine écoulée (lundi → samedi), pour l'envoi du lundi.
const isoD = d => { const x = new Date(d); x.setMinutes(x.getMinutes() - x.getTimezoneOffset()); return x.toISOString().slice(0, 10); };
function lastWeek(){ const n = new Date(); const lun = new Date(n); lun.setDate(n.getDate() - ((n.getDay() + 6) % 7) - 7); const sam = new Date(lun); sam.setDate(lun.getDate() + 5); return [isoD(lun), isoD(sam)]; }
const moisLab = ym => new Date(ym + "-15").toLocaleDateString("fr-FR", {month:"long", year:"numeric"});

function destPicker(key, cur, match){
  const L = A.L.listes || [];
  const auto = L.find(match);
  return `<div class="row"><label>Liste de destinataires<select id="${key}-liste"><option value="">Adresses saisies à la main</option>${L.map(l => `<option value="${l.id}" ${auto && +auto.id === +l.id && !cur ? "selected" : ""}>${esc(l.nom)}</option>`).join("")}</select></label>
    <label>Envoyer à <span class="hint">· séparez les adresses par des virgules</span><textarea id="${key}-dest" rows="3">${esc(cur || (auto ? auto.emails : A.L.mail_to))}</textarea></label></div>`;
}
function bindDest(key, onChange){
  const s = $("#" + key + "-liste"); if (!s) return;
  s.onchange = () => { const l = (A.L.listes || []).find(x => +x.id === +s.value); if (l) $("#" + key + "-dest").value = l.emails; onChange && onChange($("#" + key + "-dest").value); };
  $("#" + key + "-dest").oninput = () => onChange && onChange($("#" + key + "-dest").value);
}
function vListes(){
  const L = A.L.listes || [], MS = A.L.ba_modeles;
  const marq = ids => String(ids || "").split(",").filter(Boolean).map(id => (A.L.marques.find(m => +m.id === +id) || {}).nom).filter(Boolean).join(", ");
  const form = (l = {}) => { const mk = String(l.marques || "").split(",").map(Number), zs = String(l.zones || "").split(","), md = String(l.modeles || "").split(",");
    return `<div class="lform" data-lid="${l.id || 0}"><div class="row"><label>Nom de la liste<input data-lf="nom" value="${esc(l.nom || "")}" placeholder="Ex. Marque cliente"></label>
      <label>Adresses <span class="hint">· séparées par des virgules</span><textarea data-lf="emails" rows="3">${esc(l.emails || "")}</textarea></label></div>
      <div class="muted" style="margin:4px 0">Proposée automatiquement pour</div>
      <div class="chips multi" data-lm>${A.L.marques.filter(m => +m.actif).map(m => `<button type="button" class="chip" aria-pressed="${mk.includes(+m.id)}" data-v="${m.id}">${esc(m.nom)}</button>`).join("")}</div>
      <div class="chips multi" data-lz style="margin-top:6px">${A.L.zones.map(z => `<button type="button" class="chip" aria-pressed="${zs.includes(z)}" data-v="${z}">${z}</button>`).join("")}<span class="hint" style="align-self:center">aucune zone cochée = toutes</span></div>
      <div class="chips multi" data-lb style="margin-top:6px">${Object.entries(MS).map(([k, m]) => `<button type="button" class="chip grp" aria-pressed="${md.includes(k)}" data-v="${k}">Reporting ${esc(m.nom)}</button>`).join("")}</div>
      <div class="btns"><button class="primary" data-lsave>${l.id ? "Enregistrer" : "Ajouter la liste"}</button>${l.id ? `<button class="ghost danger" data-ldel>Supprimer</button>` : ""}</div></div>`; };
  return `<div class="card"><h2>Listes de destinataires</h2><div class="muted">Au moment d'envoyer un rapport, la liste qui correspond au client et à la zone est déjà choisie. Vous pouvez toujours la changer ou modifier les adresses avant l'envoi.</div>
    ${L.map(l => `<details class="ldet"><summary><b>${esc(l.nom)}</b> <span class="muted">· ${esc(l.emails.split(",").length + " adresse" + (l.emails.split(",").length > 1 ? "s" : ""))}${marq(l.marques) ? " · " + esc(marq(l.marques)) : ""}${l.zones ? " · " + esc(l.zones.replace(/,/g, ", ")) : ""}${l.modeles ? " · reporting BA" : ""}</span></summary>${can("tout") ? form(l) : `<div class="muted">${esc(l.emails)}</div>`}</details>`).join("")}
    ${can("tout") ? `<details class="ldet"><summary><b>+ Nouvelle liste</b></summary>${form()}</details>` : ""}</div>`;
}
function bListes(){
  document.querySelectorAll(".lform").forEach(f => {
    f.querySelectorAll(".chips.multi").forEach(c => c.onclick = e => { const b = e.target.closest(".chip"); if (b) b.setAttribute("aria-pressed", b.getAttribute("aria-pressed") !== "true"); });
    const on = s => [...f.querySelectorAll(s + ' .chip[aria-pressed="true"]')].map(b => b.dataset.v);
    f.querySelector("[data-lsave]").onclick = async () => { try { await api("save_liste", {id:+f.dataset.lid || 0, nom:f.querySelector('[data-lf="nom"]').value, emails:f.querySelector('[data-lf="emails"]').value, marques:on("[data-lm]"), zones:on("[data-lz]"), modeles:on("[data-lb]")}); await reloadLists(); toast("Liste enregistrée"); go("envois"); } catch(e) { toast(e.message); } };
    const d = f.querySelector("[data-ldel]"); let armed = false;
    if (d) d.onclick = async () => { if (!armed) { armed = true; d.textContent = "Confirmer"; return; } try { await api("delete_liste", {id:+f.dataset.lid}); await reloadLists(); go("envois"); } catch(e) { toast(e.message); } };
  });
}

/* ---------- Administrateurs ---------- */
async function loadAdmins(){ const r = await api("admins"); A.admins = r.admins; A.moiEmail = r.moi; }
function vAdmins(){
  const D = {tout: "Tout gérer : logements, réservations, équipe, propriétaires, services et codes d'accès"}; // Keylia : les droits « rapports » et « envoi » de l'ancien métier ne sont pas proposés
  const form = (a = {}) => { const dr = String(a.droits || "").split(",");
    return `<div class="aform" data-aid="${a.id || 0}"><div class="row3"><label>Nom<input data-af="nom" value="${esc(a.nom || "")}" placeholder="Ex. Salma Idrissi"></label><label>E-mail (identifiant)<input data-af="email" type="email" value="${esc(a.email || "")}"></label>
      <label>${a.id ? "Nouveau mot de passe <span class=\"hint\">· laisser vide pour le garder</span>" : "Mot de passe <span class=\"hint\">· 12 caractères minimum</span>"}<input data-af="password" type="text" autocomplete="new-password"></label></div>
      <div class="muted" style="margin:4px 0">Droits <span class="hint">· la consultation est toujours permise</span></div>
      <div class="pjs">${Object.entries(D).map(([k, l]) => `<label><input type="checkbox" data-ad="${k}" ${dr.includes(k) ? "checked" : ""}> ${esc(l)}</label>`).join("")}</div>
      ${a.id ? `<label class="ck"><input type="checkbox" data-af="actif" ${+a.actif ? "checked" : ""}><span>Compte actif</span></label>` : ""}
      <div class="btns"><button class="primary" data-asave>${a.id ? "Enregistrer" : "Créer le compte"}</button>${a.id ? `<button class="ghost danger" data-adel>Supprimer le compte</button>` : ""}</div></div>`; };
  const lab = d => String(d || "").split(",").filter(Boolean).map(k => k === "tout" ? "Tout gérer" : "").filter(Boolean).join(" · ") || "Consultation seule";
  return `<div class="card accent"><h2>Comptes administrateurs</h2>
    <div class="muted">Les dirigeant(e)s ont tous les droits. Eux seuls créent les comptes, donnent les droits et peuvent nommer d'autres dirigeant(e)s ; ceux déclarés à la création de l'espace restent toujours dirigeants. Chaque autre personne se connecte avec son e-mail et son mot de passe, avec les droits cochés :</div>
    <ul class="hint" style="margin:6px 0 0 18px;padding:0"><li><b>Consultation</b> (toujours) : voir le planning, les séjours, les missions, les avis et les rapports.</li><li><b>Tout gérer</b> : logements, réservations, équipe, propriétaires, services aux voyageurs, envoi des codes d'accès.</li></ul>
    <div class="hint" style="margin-top:6px">Chaque compte se connecte avec son e-mail et son mot de passe personnel. La double authentification est obligatoire pour tous les comptes : elle s'active à la première connexion. Téléphone perdu : un(e) dirigeant(e) la réinitialise ici ; si aucun dirigeant ne peut se connecter, contactez Keylia.</div></div>
    <div class="card tablebox"><h2>${A.admins.length} compte${A.admins.length > 1 ? "s" : ""}</h2>
    ${A.admins.map(a => a.fondateur ? `<details class="ldet"><summary><b>${esc(a.nom)}</b> <span class="pill p-ok">Dirigeant(e)</span> ${tfPill(a)}<span class="muted">· ${esc(a.email)}${a.derniere_connexion ? " · dernière connexion " + esc(a.derniere_connexion.slice(0, 16)) : a.id ? "" : " · pas encore connecté"}</span></summary>
      <div class="row"><label>Nouveau mot de passe <span class="hint">· 12 caractères minimum, à lui transmettre</span><input data-ppw="${esc(a.email)}" type="text" autocomplete="new-password"></label><label>&nbsp;<button class="primary" data-ppgo="${esc(a.email)}">Définir le mot de passe</button></label></div>${tfReinit(a)}</details>`
      : `<details class="ldet"><summary><b>${esc(a.nom)}</b> ${a.principal ? `<span class="pill p-ok">Dirigeant(e)</span> ` : ""}${tfPill(a)}<span class="muted">· ${esc(a.email)} · ${a.principal ? "tous les droits" : esc(lab(a.droits))}${+a.actif ? "" : " · désactivé"}${a.derniere_connexion ? " · dernière connexion " + esc(a.derniere_connexion.slice(0, 16)) : ""}</span></summary>${form(a)}${tfReinit(a)}
        ${a.id && a.email !== A.moiEmail ? `<div class="btns"><button class="ghost" data-adir="${a.id}" data-on="${a.principal ? 0 : 1}">${a.principal ? "Retirer le rôle de dirigeant(e)" : "Nommer dirigeant(e)"}</button></div><div class="hint">Un(e) dirigeant(e) a tous les droits : paye, contrats, facturation, comptes, journal des accès.</div>` : ""}</details>`).join("")}
    <details class="ldet" ${A.admins.length ? "" : "open"}><summary><b>+ Nouveau compte</b></summary>${form()}</details></div>`;
}
function bAdmins(){
  document.querySelectorAll("[data-adir]").forEach(b => b.onclick = async () => { if (b.dataset.armed !== "1") { b.dataset.armed = "1"; b.textContent = "Confirmer"; return; }
    try { await api("admin_dirigeant", {id:+b.dataset.adir, dirigeant:+b.dataset.on}); toast(+b.dataset.on ? "Nommé(e) dirigeant(e)" : "Rôle de dirigeant(e) retiré"); await loadAdmins(); go("admins"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-tfreinit]").forEach(b => b.onclick = async () => { try { await api("tf_reinit", {id:+b.dataset.tfreinit}); toast("Double authentification réinitialisée : nouvelle activation à sa prochaine connexion"); go("admins"); } catch(e) { toast(e.message); } });
  document.querySelectorAll("[data-ppgo]").forEach(b => b.onclick = async () => { const e = b.dataset.ppgo, pw = document.querySelector(`[data-ppw="${e}"]`).value;
    try { await api("admin_principal_pw", {email:e, password:pw}); toast("Mot de passe enregistré : " + e + " peut se connecter avec"); go("admins"); } catch(x) { toast(x.message); } });
  document.querySelectorAll(".aform").forEach(f => {
    f.querySelector("[data-asave]").onclick = async () => {
      const body = {id:+f.dataset.aid || 0, nom:f.querySelector('[data-af="nom"]').value, email:f.querySelector('[data-af="email"]').value, password:f.querySelector('[data-af="password"]').value,
        droits:[...f.querySelectorAll("[data-ad]:checked")].map(i => i.dataset.ad), actif:f.querySelector('[data-af="actif"]') ? (f.querySelector('[data-af="actif"]').checked ? 1 : 0) : 1};
      try { await api("save_admin", body); toast("Compte enregistré"); go("admins"); } catch(e) { toast(e.message); } };
    const d = f.querySelector("[data-adel]"); let armed = false;
    if (d) d.onclick = async () => { if (!armed) { armed = true; d.textContent = "Confirmer la suppression"; return; } try { await api("delete_admin", {id:+f.dataset.aid}); go("admins"); } catch(e) { toast(e.message); } };
  });
}
const tfPill = a => a.id ? (+a.totp_actif ? `<span class="pill p-ok">2FA</span> ` : `<span class="pill">2FA à activer</span> `) : "";
const tfReinit = a => a.id && +a.totp_actif && a.email !== A.moiEmail ? `<div class="btns"><button class="ghost" data-tfreinit="${a.id}">Réinitialiser la double authentification (téléphone perdu)</button></div>` : "";
function myAccount(){
  document.getElementById("ab-acc")?.remove();
  const m = document.createElement("div"); m.className = "modal"; m.id = "ab-acc";
  m.innerHTML = `<div class="modal-box"><button class="ghost modal-x" data-x>✕</button><h2>Mon compte</h2><h2 style="font-size:14px">Mot de passe</h2><div class="muted">${esc(A.L.moi.nom)}</div>
    <label>Mot de passe actuel<input type="password" id="mp-a" autocomplete="current-password"></label><label>Nouveau mot de passe <span class="hint">· 12 caractères minimum</span><input type="password" id="mp-n" autocomplete="new-password"></label>
    <div class="btns"><button class="primary" id="mp-go">Changer</button></div>
    <h2 style="margin-top:10px">Double authentification</h2><div id="tf-box" class="muted">Chargement…</div></div>`;
  document.body.appendChild(m); tfBox();
  m.onclick = e => { if (e.target === m || e.target.closest("[data-x]")) m.remove(); };
  $("#mp-go").onclick = async () => { try { await api("my_password", {actuel:$("#mp-a").value, nouveau:$("#mp-n").value}); toast("Mot de passe changé"); m.remove(); } catch(e) { toast(e.message); } };
}

/* ---------- Double authentification (Mon compte) ---------- */
async function tfBox(){
  const box = $("#tf-box"); if (!box) return; let E;
  try { E = await api("tf_etat"); } catch(e) { box.textContent = e.message; return; }
  if (!E.possible) { box.textContent = "Connectez-vous avec votre e-mail pour activer la double authentification."; return; }
  if (E.actif) {
    box.innerHTML = `<div><span class="pill p-ok">Activée</span> Un code de votre téléphone est demandé à chaque connexion. Codes de secours restants : <b>${E.secours}</b>.</div>
      ${E.obligatoire ? `<div class="hint">Obligatoire pour l'administration. Nouveau téléphone : réactivez-la ci-dessous, l'ancien cesse de fonctionner et de nouveaux codes de secours sont donnés.</div><div class="btns"><button class="ghost" id="tf-go">Changer de téléphone</button></div>`
        : `<label>Mot de passe (pour désactiver)<input type="password" id="tf-pw" autocomplete="current-password"></label><div class="btns"><button class="ghost danger" id="tf-off">Désactiver</button></div>`}`;
    if ($("#tf-off")) $("#tf-off").onclick = async () => { try { await api("tf_desactiver", {password:$("#tf-pw").value}); toast("Double authentification désactivée"); tfBox(); } catch(e) { toast(e.message); } };
    if (!$("#tf-go")) return;
  } else
{ box.innerHTML = `<div>Protégez votre compte : en plus du mot de passe, un code à 6 chiffres de votre téléphone sera demandé. Fortement conseillé pour les dirigeants (paye, IBAN, n° de sécurité sociale).</div><div class="btns"><button class="primary" id="tf-go">Activer</button></div>`; }
  $("#tf-go").onclick = async () => { try { const P = await api("tf_preparer");
    box.innerHTML = `<div>1. Installez une appli d'authentification (Google Authenticator, Microsoft Authenticator…) sur votre téléphone.<br>2. Scannez ce QR code avec l'appli.</div>
      <div id="tf-qr" style="align-self:center;background:#fff;padding:10px;border-radius:12px"></div><div class="hint">Sans appareil photo : saisissez la clé <b>${esc(P.secret)}</b></div>
      <label>3. Code affiché par l'appli<input id="tf-c" inputmode="numeric" maxlength="6" class="pin" placeholder="123456"></label><div class="btns"><button class="primary" id="tf-ok">Activer</button></div>`;
    try { const q = qrcode(0, "M"); q.addData(P.uri); q.make(); $("#tf-qr").innerHTML = q.createSvgTag({cellSize:5, margin:0}); } catch(x) { $("#tf-qr").remove(); }
    $("#tf-ok").onclick = async () => { try { const r = await api("tf_activer", {code:$("#tf-c").value});
      box.innerHTML = `<div><span class="pill p-ok">Activée</span></div><div><b>Codes de secours</b> : chacun ne sert qu'une fois, si vous perdez votre téléphone. Notez-les et gardez-les en lieu sûr, ils ne seront plus affichés.</div>
        <div class="tf-codes">${r.secours.map(c => `<code>${esc(c)}</code>`).join("")}</div>`; } catch(e) { toast(e.message); } };
  } catch(e) { toast(e.message); } };
}

/* ---------- Objectifs fixés par l'agence pour les animations ---------- */
const OB = { marque:"", zone:"" };
function vObj(){
  const mk = A.L.marques.filter(m => +m.actif), m = mk.find(x => String(x.id) === String(OB.marque));
  const sous = m && m.sous_marques ? m.sous_marques.split(",").map(x => x.trim()).filter(Boolean) : [];
  const mags = A.L.magasins.filter(x => +x.actif && (!OB.zone || x.zone === OB.zone));
  const today = new Date(Date.now() - new Date().getTimezoneOffset() * 6e4).toISOString().slice(0, 10);
  const L = A.obj || [], who = o => o.animatrice || "Toutes les intervenants", per = o => o.date_debut === o.date_fin ? fmtD(o.date_debut) : "du " + fmtD(o.date_debut) + " au " + fmtD(o.date_fin);
  const eur = v => (+v).toLocaleString("fr-FR", {maximumFractionDigits:2}) + " €";
  return `${can("rapports") ? `<div class="card accent"><h2>Fixer un objectif d'animation</h2>
    <div class="muted">L'objectif s'affiche sur l'appli de l'intervenant(e), verrouillé : elle ne peut pas le modifier, et c'est lui qui part dans le compte rendu et les rapports.</div>
    <div class="row3"><label>Intervenant(e)<select id="ob-anim"><option value="">Toutes les intervenants</option>${A.L.animatrices.filter(a => +a.actif !== 0).map(a => `<option value="${a.id}">${esc(nm(a))}</option>`).join("")}</select></label>
      <label>Marque<select id="ob-marque"><option value="">Choisir…</option>${mk.map(x => `<option value="${x.id}" ${String(OB.marque) === String(x.id) ? "selected" : ""}>${esc(x.nom)}</option>`).join("")}</select></label>
      <label>Zone<select id="ob-zone"><option value="">Toutes zones</option>${A.L.zones.map(z => `<option ${OB.zone === z ? "selected" : ""}>${z}</option>`).join("")}</select></label></div>
    <div class="row3"><label>Point d'intervention<select id="ob-mag"><option value="">Tous les points d'intervention</option>${mags.map(x => `<option value="${x.id}">${esc(x.nom)} · ${esc(x.ville)}</option>`).join("")}</select></label>
      <label>Du<input type="date" id="ob-d1" value="${today}"></label><label>Au <span class="hint">· même jour si une seule date</span><input type="date" id="ob-d2" value="${today}"></label></div>
    ${sous.length ? `<div class="row3">${sous.map(n => `<label>Objectif ${esc(n)} par jour (€)<input data-obs="${esc(n)}" inputmode="decimal"></label>`).join("")}</div>`
      : `<div class="row3"><label>Objectif par jour (€)<input id="ob-mt" inputmode="decimal" placeholder="Ex. 1500"></label></div>`}
    <button class="primary" id="ob-save">Enregistrer l'objectif</button>
    <div class="hint">Si plusieurs objectifs se recoupent, le plus précis l'emporte : l'intervenant(e) nommée avant « toutes », le point d'intervention précis avant « tous ».</div></div>` : `<div class="card"><div class="muted">Objectifs fixés par ${AG} pour les animations : ils s'affichent verrouillés sur l'appli des intervenants.</div></div>`}
  <div class="card"><h2>Objectifs en cours et récents</h2>
    ${L.length ? `<div class="tablewrap"><table><tr><th>Période</th><th>Intervenant(e)</th><th>Marque</th><th>Point d'intervention</th><th>Objectif par jour</th><th>Saisi par</th><th></th></tr>
      ${L.map(o => `<tr><td>${per(o)}</td><td>${esc(who(o))}</td><td><b>${esc(o.marque)}</b></td><td>${esc(o.magasin || "Tous")}</td>
        <td>${eur(o.montant)}${Object.keys(o.sous || {}).length ? `<div class="hint">${Object.entries(o.sous).map(([n, v]) => esc(n) + " " + eur(v)).join(" · ")}</div>` : ""}</td><td class="muted">${esc(o.par)}</td>
        <td>${can("rapports") ? `<button class="ghost danger" data-obdel="${o.id}">Supprimer</button>` : ""}</td></tr>`).join("")}</table></div>`
      : `<div class="empty">Aucun objectif fixé pour l'instant.</div>`}</div>`;
}
function bObj(){
  if (!$("#ob-save")) return;
  $("#ob-marque").onchange = e => { OB.marque = e.target.value; go("objectifs"); };
  $("#ob-zone").onchange = e => { OB.zone = e.target.value; go("objectifs"); };
  $("#ob-save").onclick = async () => {
    const sous = {}; document.querySelectorAll("[data-obs]").forEach(i => sous[i.dataset.obs] = i.value);
    const body = {animatrice_id:$("#ob-anim").value, marque_id:$("#ob-marque").value, magasin_id:$("#ob-mag").value, date_debut:$("#ob-d1").value, date_fin:$("#ob-d2").value || $("#ob-d1").value, montant:($("#ob-mt") || {}).value || "", sous};
    if (!body.marque_id) return toast("Choisissez la marque.");
    const b = $("#ob-save"); b.disabled = true;
    try { await api("objectif_anim_save", body); toast("Objectif enregistré et verrouillé sur l'appli"); go("objectifs"); } catch(e) { toast(e.message); b.disabled = false; }
  };
  document.querySelectorAll("[data-obdel]").forEach(b => b.onclick = async () => {
    if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer"; return; }
    try { await api("objectif_anim_delete", {id:+b.dataset.obdel}); toast("Objectif supprimé"); go("objectifs"); } catch(e) { toast(e.message); } });
}
