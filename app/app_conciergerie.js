// ORVIA : l'appli de l'équipe terrain. Missions du jour, check-list par pièce, photos obligatoires, signalement, validation.
// Français ou arabe au choix de l'agent. Sans réseau, les photos attendent sur le téléphone et partent dès le retour de la connexion.
const CZT = {
  fr:{bonjour:"Bonjour", missions:"Mes missions", aucune:"Aucune mission prévue pour le moment.", auj:"Aujourd'hui", retard:"En retard", menage:"Ménage", controle:"Contrôle", maintenance:"Maintenance",
    a_faire:"À faire", en_cours:"En cours", faite:"Terminée", refusee:"À reprendre", ouvrir:"Ouvrir", retour:"‹ Mes missions", acces:"Accès", linge:"Linge à poser", photos:"photo(s) demandée(s)",
    prendre:"Prendre une photo", valider:"Pièce terminée", rouvrir:"Rouvrir", terminer:"Terminer la mission", manque:"Il reste à faire :", signaler:"Signaler un problème", decrire:"Décrivez le problème",
    bloquant:"Le logement ne peut pas accueillir de voyageurs", envoyer:"Envoyer", annuler:"Annuler", merci:"Mission terminée. Merci !", attente:"photo(s) en attente de réseau", docs:"Mes documents", fiche:"Ma fiche",
    signale:"Problème signalé à la conciergerie.", consigne:"Consigne", envoi:"Envoi…", langue:"العربية"},
  ar:{bonjour:"مرحبا", missions:"المهام ديالي", aucune:"ما كاين حتى مهمة دابا.", auj:"اليوم", retard:"متأخرة", menage:"التنظيف", controle:"المراقبة", maintenance:"الصيانة",
    a_faire:"باقي", en_cours:"جارية", faite:"سالات", refusee:"خاصها تعاود", ouvrir:"حل", retour:"‹ المهام ديالي", acces:"الدخول", linge:"الفراش اللي خاصو يتحط", photos:"تصاور مطلوبين",
    prendre:"خود تصويرة", valider:"البيت سالا", rouvrir:"عاود حل", terminer:"سالي المهمة", manque:"باقي خاصك تدير:", signaler:"علّم على مشكل", decrire:"شرح المشكل",
    bloquant:"السكن ما يقدرش يستقبل الضيوف", envoyer:"صيفط", annuler:"إلغاء", merci:"المهمة سالات. شكرا!", attente:"تصاور كيتسناو الريزو", docs:"الوثائق ديالي", fiche:"البطاقة ديالي",
    signale:"المشكل وصل للكونسيرجري.", consigne:"التعليمات", envoi:"كيتصيفط…", langue:"Français"}
};
let CZL = (() => { try { return localStorage.getItem("orvia-agent-l") || "fr"; } catch(e) { return "fr"; } })();
const czt = k => (CZT[CZL] || CZT.fr)[k] || k;
const CZA = { M:null, m:null };
async function czaapi(action, body, query){
  const isForm = body instanceof FormData;
  const opt = body ? {method:"POST", credentials:"same-origin", headers: isForm ? {"X-Requested-With":"amaya"} : {"Content-Type":"application/json","X-Requested-With":"amaya"}, body: isForm ? body : JSON.stringify(body)} : {credentials:"same-origin"};
  let r; try { r = await fetch("conciergerie_api.php?action=" + action + (query ? "&" + query : ""), opt); } catch(e) { const x = new Error("reseau"); x.reseau = true; throw x; }
  const d = await r.json().catch(() => ({})); if (!r.ok) { const e = new Error(d.error || "Erreur " + r.status); e.status = r.status; throw e; } return d;
}
const czaDir = () => { document.documentElement.dir = CZL === "ar" ? "rtl" : "ltr"; document.documentElement.lang = CZL; };
const czaD = iso => new Date(iso + "T12:00:00").toLocaleDateString(CZL === "ar" ? "ar-MA" : "fr-FR", {weekday:"long", day:"numeric", month:"long"});

/* ---------- Photos en attente (sans réseau) : IndexedDB ---------- */
const czQ = {
  db(){ return new Promise((ok, ko) => { const r = indexedDB.open("orvia-photos", 1); r.onupgradeneeded = () => r.result.createObjectStore("q", {autoIncrement:true}); r.onsuccess = () => ok(r.result); r.onerror = () => ko(r.error); }); },
  async add(item){ const d = await this.db(); return new Promise((ok, ko) => { const t = d.transaction("q", "readwrite"); t.objectStore("q").add(item); t.oncomplete = ok; t.onerror = () => ko(t.error); }); },
  async all(){ const d = await this.db(); return new Promise(ok => { const out = []; const c = d.transaction("q").objectStore("q").openCursor(); c.onsuccess = e => { const x = e.target.result; if (x) { out.push([x.key, x.value]); x.continue(); } else ok(out); }; }); },
  async del(k){ const d = await this.db(); return new Promise(ok => { const t = d.transaction("q", "readwrite"); t.objectStore("q").delete(k); t.oncomplete = ok; }); },
  async vider(){ let n = 0; try { for (const [k, v] of await this.all()) { const f = new FormData(); f.append("id", v.id); f.append("piece", v.piece); f.append("photo", v.blob, "photo.jpg");
      try { await czaapi("mission_photo", f); await this.del(k); n++; } catch(e) { if (e.reseau) break; await this.del(k); } } } catch(e) {} return n; },
  async nb(){ try { return (await this.all()).length; } catch(e) { return 0; } }
};
addEventListener("online", async () => { if (await czQ.vider()) { if (S.view === "czm" && CZA.m) czOuvrir(CZA.m.id); else if (S.view === "cz") czHome(); } });

/* ---------- Accueil : mes missions ---------- */
async function czHome(){
  S.view = "cz"; czaDir();
  try { CZA.M = await czaapi("mes_missions"); } catch(e) { if (!CZA.M) CZA.M = {missions:[], aujourdhui:new Date().toISOString().slice(0, 10)}; }
  const M = CZA.M.missions, auj = CZA.M.aujourdhui, att = await czQ.nb();
  const carte = m => `<button class="card cz-m" data-m="${m.id}" style="display:grid;gap:4px;text-align:start;width:100%;font:inherit;color:inherit;cursor:pointer">
      <div style="display:flex;justify-content:space-between;gap:8px"><b>${esc(m.logement)}</b><span class="pill ${m.statut === "faite" ? "p-ok" : m.statut === "refusee" ? "p-bad" : m.statut === "en_cours" ? "p-warn" : ""}">${esc(czt(m.statut))}</span></div>
      <div class="muted">${m.date < auj ? `<b style="color:#8e3b2f">${czt("retard")}</b> · ` : ""}${esc(czt(m.type))}${m.heure ? " · " + esc(m.heure) : ""} · ${esc(m.ville || "")}</div>${m.note ? `<div class="hint">${esc(m.note)}</div>` : ""}</button>`;
  const jours = {}; M.forEach(m => (jours[m.date < auj ? auj : m.date] = jours[m.date < auj ? auj : m.date] || []).push(m));
  $("#view").innerHTML = `<div class="home-hero"><div class="hk">${AG}</div><div class="ht">${czt("bonjour")} ${esc(S.me.prenom)}</div>
      <div style="margin-top:8px"><button class="btn ghost" id="czl" style="color:inherit;border-color:currentColor">${czt("langue")}</button></div></div>
    ${att ? `<div class="card accent">${att} ${czt("attente")}</div>` : ""}
    <h2 style="margin:18px 4px 8px">${czt("missions")}</h2>
    ${Object.keys(jours).sort().map(d => `<div class="muted" style="margin:14px 4px 6px">${d === auj ? czt("auj") : esc(czaD(d))}</div>${jours[d].map(carte).join("")}`).join("") || `<div class="card muted">${czt("aucune")}</div>`}
    <div class="tiles" style="margin-top:18px"><button class="tile" id="t-docs"><span class="tt">${czt("docs")}</span></button><button class="tile" id="t-fiche"><span class="tt">${czt("fiche")}</span></button></div>`;
  $("#czl").onclick = () => { CZL = CZL === "ar" ? "fr" : "ar"; try { localStorage.setItem("orvia-agent-l", CZL); } catch(e) {} czHome(); };
  document.querySelectorAll("[data-m]").forEach(b => b.onclick = () => czOuvrir(+b.dataset.m));
  $("#t-docs").onclick = () => { S.view = "docs"; document.documentElement.dir = "ltr"; render(); };
  $("#t-fiche").onclick = () => { S.view = "fiche"; document.documentElement.dir = "ltr"; render(); };
  czQ.vider();
}

/* ---------- Une mission ---------- */
async function czOuvrir(id){
  try { CZA.m = (await czaapi("ma_mission", null, "id=" + id)).mission; } catch(e) { return toast(e.reseau ? "Pas de réseau." : e.message); }
  S.view = "czm"; czMis();
  if (CZA.m.statut === "a_faire") czaapi("mission_debut", {id}).catch(() => {});
}
function czMis(){
  czaDir(); const m = CZA.m, fini = m.statut === "faite";
  $("#view").innerHTML = `<button class="btn ghost" id="czb">${czt("retour")}</button>
    <div class="card"><h2 style="margin:0">${esc(m.logement)}</h2><div class="muted">${esc(czt(m.type))} · ${esc(czaD(m.date))}${m.heure ? " · " + esc(m.heure) : ""}</div><div class="muted">${esc(m.adresse)} ${esc(m.ville)}</div>
      ${m.note ? `<div><b>${czt("consigne")} :</b> ${esc(m.note)}</div>` : ""}${m.acces ? `<details><summary><b>${czt("acces")}</b></summary><div style="white-space:pre-line">${esc(m.acces)}</div></details>` : ""}
      ${m.kit_linge ? `<div><b>${czt("linge")} :</b> ${esc(m.kit_linge)}</div>` : ""}</div>
    ${m.pieces.map((p, i) => `<div class="card" style="display:grid;gap:8px"><div style="display:flex;justify-content:space-between;gap:8px"><b>${esc(p.piece)}</b>${p.ok ? `<span class="pill p-ok">✓</span>` : `<span class="pill">${p.urls.length}/${p.photos}</span>`}</div>
      ${p.consignes ? `<div class="hint">${esc(p.consignes)}</div>` : ""}<div class="hint">${p.photos} ${czt("photos")}</div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">${p.urls.map(u => `<img src="${esc(u)}" alt="" style="width:76px;height:76px;object-fit:cover">`).join("")}</div>
      ${fini ? "" : `<div style="display:flex;gap:8px;flex-wrap:wrap"><label class="btn" style="cursor:pointer">${czt("prendre")}<input type="file" accept="image/*" capture="environment" data-ph="${i}" hidden></label>
        <button class="${p.ok ? "btn ghost" : "primary"}" data-ok="${i}" data-v="${p.ok ? 0 : 1}">${p.ok ? czt("rouvrir") : czt("valider")}</button></div>`}</div>`).join("")}
    ${fini ? `<div class="card accent">${czt("merci")}</div>` : `<button class="primary" id="czt" style="width:100%;padding:16px">${czt("terminer")}</button>`}
    <button class="btn ghost" id="czs" style="width:100%;margin-top:10px">${czt("signaler")}</button>`;
  $("#czb").onclick = czHome;
  document.querySelectorAll("[data-ph]").forEach(inp => inp.onchange = async () => {
    const f = inp.files[0]; if (!f) return; const i = +inp.dataset.ph; const lab = inp.parentElement; lab.firstChild.textContent = czt("envoi");
    const blob = await shrink(f), fd = new FormData(); fd.append("id", m.id); fd.append("piece", i); fd.append("photo", blob, "photo.jpg");
    try { await czaapi("mission_photo", fd); }
    catch(e) { if (e.reseau) { await czQ.add({id:m.id, piece:i, blob}); toast(czt("attente")); } else toast(e.message); }
    czOuvrir(m.id);
  });
  document.querySelectorAll("[data-ok]").forEach(b => b.onclick = async () => { try { await czaapi("mission_piece", {id:m.id, piece:+b.dataset.ok, ok:+b.dataset.v === 1}); czOuvrir(m.id); } catch(e) { toast(e.message); } });
  if ($("#czt")) $("#czt").onclick = async () => { try { const r = await czaapi("mission_terminer", {id:m.id}); if (r.ok === false) return toast(czt("manque") + " " + r.manques.join(", ")); toast(czt("merci")); czOuvrir(m.id); } catch(e) { toast(e.message); } };
  $("#czs").onclick = () => {
    $("#view").insertAdjacentHTML("beforeend", `<div class="card accent" id="czsf" style="display:grid;gap:10px"><b>${czt("signaler")}</b><textarea id="czsd" rows="3" placeholder="${esc(czt("decrire"))}"></textarea>
      <label class="btn" style="cursor:pointer">${czt("prendre")}<input type="file" accept="image/*" capture="environment" id="czsp" hidden></label>
      <label class="ck"><input type="checkbox" id="czsb"><span>${czt("bloquant")}</span></label><div style="display:flex;gap:8px"><button class="primary" id="czso">${czt("envoyer")}</button><button class="btn ghost" id="czsk">${czt("annuler")}</button></div></div>`);
    $("#czsf").scrollIntoView({behavior:"smooth"});
    $("#czsk").onclick = () => $("#czsf").remove();
    $("#czso").onclick = async () => { const fd = new FormData(); fd.append("mission", m.id); fd.append("description", $("#czsd").value); if ($("#czsb").checked) fd.append("bloquant", "1");
      const f = $("#czsp").files[0]; if (f) fd.append("photo", await shrink(f), "photo.jpg");
      try { await czaapi("incident_signaler", fd); toast(czt("signale")); $("#czsf").remove(); } catch(e) { toast(e.reseau ? "Pas de réseau." : e.message); } };
  };
}
