// ORVIA : l'appli de l'équipe terrain. Missions du jour, check-list par pièce, photos obligatoires, signalement, validation.
// Français ou arabe au choix de l'agent. Sans réseau, les photos attendent sur le téléphone et partent dès le retour de la connexion.
const CZT = {
  fr:{bonjour:"Bonjour", missions:"Mes missions", aucune:"Aucune mission prévue pour le moment.", auj:"Aujourd'hui", retard:"En retard", menage:"Ménage", controle:"Contrôle", maintenance:"Maintenance",
    a_faire:"À faire", en_cours:"En cours", faite:"Terminée", refusee:"À reprendre", ouvrir:"Ouvrir", retour:"‹ Mes missions", acces:"Accès", linge:"Linge à poser", photos:"photo(s) demandée(s)",
    prendre:"Prendre une photo", valider:"Pièce terminée", rouvrir:"Rouvrir", terminer:"Terminer la mission", manque:"Il reste à faire :", signaler:"Signaler un problème", decrire:"Décrivez le problème",
    bloquant:"Le logement ne peut pas accueillir de voyageurs", envoyer:"Envoyer", annuler:"Annuler", merci:"Mission terminée. Merci !", attente:"photo(s) en attente de réseau", docs:"Mes documents", fiche:"Ma fiche",
    signale:"Problème signalé à la conciergerie.", consigne:"Consigne", envoi:"Envoi…", langue:"العربية", nmission:"mission", nmissions:"missions", premiere:"la première à", rien:"Rien de prévu aujourd'hui.", accueil:"Accueil", transfert:"Transfert", visite:"Visite", autre:"Autre", pieces:"pièces faites", maps:"Itinéraire", repere:"Repère", chemin:"Le chemin", code:"Code", jarrive:"J'arrive", commence:"Mission commencée", prochaine:"Prochaine mission", sur:"sur", etapes:"pièces", restent:"pièce(s) restante(s)", nphotos:"photo(s)", aprendre:"Prendre", suivantes:"Ensuite", tout:"Tout est prêt", pos:"Votre position est relevée seulement quand vous appuyez sur « J'arrive » et « Terminer la mission »."},
  ar:{bonjour:"مرحبا", missions:"المهام ديالي", aucune:"ما كاين حتى مهمة دابا.", auj:"اليوم", retard:"متأخرة", menage:"التنظيف", controle:"المراقبة", maintenance:"الصيانة",
    a_faire:"باقي", en_cours:"جارية", faite:"سالات", refusee:"خاصها تعاود", ouvrir:"حل", retour:"‹ المهام ديالي", acces:"الدخول", linge:"الفراش اللي خاصو يتحط", photos:"تصاور مطلوبين",
    prendre:"خود تصويرة", valider:"البيت سالا", rouvrir:"عاود حل", terminer:"سالي المهمة", manque:"باقي خاصك تدير:", signaler:"علّم على مشكل", decrire:"شرح المشكل",
    bloquant:"السكن ما يقدرش يستقبل الضيوف", envoyer:"صيفط", annuler:"إلغاء", merci:"المهمة سالات. شكرا!", attente:"تصاور كيتسناو الريزو", docs:"الوثائق ديالي", fiche:"البطاقة ديالي",
    signale:"المشكل وصل للكونسيرجري.", consigne:"التعليمات", envoi:"كيتصيفط…", langue:"Français", nmission:"مهمة", nmissions:"مهام", premiere:"الأولى مع", rien:"ما كاين والو اليوم.", accueil:"الاستقبال", transfert:"النقل", visite:"الزيارة", autre:"أخرى", pieces:"بيوت سالاو", maps:"الطريق", repere:"علامة", chemin:"الطريق للدار", code:"الكود", jarrive:"وصلت", commence:"المهمة بدات", prochaine:"المهمة الجاية", sur:"من", etapes:"بيوت", restent:"بيوت باقيين", nphotos:"تصاور", aprendre:"خود", suivantes:"من بعد", tout:"كلشي واجد", pos:"البلاصة ديالك كاتتسجل غير ملي كاتضغط على «وصلت» و«سالي المهمة»."}
};
const CZ_TYPE_C = {menage:"#3E8A68", controle:"#A07A38", maintenance:"#A65A3A", accueil:"#3A6E8F", transfert:"#3A6E8F", visite:"#6B5B95", autre:"#5A5F5B"};
const czPill = st => st === "faite" ? "p-ok" : st === "refusee" ? "p-bad" : st === "en_cours" ? "p-warn" : "p-todo";
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
  const retard = m => m.date < auj && m.statut !== "faite";
  const ic = m => m.statut === "faite" ? `<span class="kt-ic ok">✓</span>` : m.statut === "en_cours" ? `<span class="kt-ic run"></span>` : `<span class="kt-ic wait${retard(m) ? " late" : ""}"></span>`;
  const carte = m => `<button class="kt-row s-${esc(m.statut)}" data-m="${m.id}">${ic(m)}
      <span class="tx"><b>${esc(m.logement)}</b><small${retard(m) ? ' class="late"' : ""}>${esc(m.heure || "")}${m.heure ? " · " : ""}${esc(czt(m.type))}${m.ville ? " · " + esc(m.ville) : ""}${retard(m) ? " · " + czt("retard") : ""}</small>
      ${m.note ? `<small class="nt">${esc(m.note)}</small>` : ""}</span>
      ${m.vignette ? `<span class="th"><img src="${esc(m.vignette)}" alt="" loading="lazy"></span>` : `<span class="go">›</span>`}</button>`;
  const dujour = M.filter(m => (m.date <= auj) && m.statut !== "faite"), prem = dujour.find(m => m.heure);
  const next = M.find(m => m.statut === "en_cours") || dujour[0] || M.find(m => m.statut !== "faite");
  const jours = {}; M.filter(m => m !== next).forEach(m => (jours[m.date < auj ? auj : m.date] = jours[m.date < auj ? auj : m.date] || []).push(m));
  const pc = next && next.pieces ? Math.round(100 * next.pieces_ok / next.pieces) : 0;
  $("#view").innerHTML = `<div class="kt-hero"><div class="k1">${AG}</div><div class="k2">${czt("bonjour")} ${esc(S.me.prenom)}</div>
      <div class="k3">${dujour.length ? `${czt("auj")}, <b>${dujour.length} ${czt(dujour.length > 1 ? "nmissions" : "nmission")}</b>${prem ? `, ${czt("premiere")} ${esc(prem.heure)}` : ""}` : czt("rien")}</div>
      <button class="kt-lang" id="czl">${czt("langue")}</button></div>
    ${next ? `<button class="kt-next" data-m="${next.id}"><span class="mt"><b>${esc(next.heure || "–")}</b><span class="chip" style="--c:${CZ_TYPE_C[next.type] || "#5A5F5B"}">${esc(czt(next.type))}</span></span>
      <span class="nm">${esc(next.logement)}</span><small>${next.date === auj ? czt("auj") : esc(czaD(next.date))}${next.ville ? " · " + esc(next.ville) : ""}</small>
      ${next.pieces ? `<span class="kt-prog"><i style="width:${pc}%"></i></span><span class="pgl">${next.pieces_ok} ${czt("sur")} ${next.pieces} ${czt("etapes")}</span>` : ""}
      <span class="mp">${esc(czt(next.statut))}</span></button>` : `<div class="kt-next vide"><span class="nm">${czt("tout")}</span><small>${czt("aucune")}</small></div>`}
    ${att ? `<div class="kt-att">${att} ${czt("attente")}</div>` : ""}
    ${Object.keys(jours).sort().map(d => `<div class="kt-jour">${d === auj ? czt("auj") : esc(czaD(d))}</div><div class="kt-list">${jours[d].map(carte).join("")}</div>`).join("")}
`;
  $("#czl").onclick = () => { CZL = CZL === "ar" ? "fr" : "ar"; try { localStorage.setItem("orvia-agent-l", CZL); } catch(e) {} czHome(); };
  document.querySelectorAll("[data-m]").forEach(b => b.onclick = () => czOuvrir(+b.dataset.m));
  czQ.vider();
}

/* ---------- Une mission ---------- */
const czPos = () => new Promise(ok => { if (!navigator.geolocation) return ok({}); navigator.geolocation.getCurrentPosition(p => ok({lat:p.coords.latitude, lng:p.coords.longitude}), () => ok({}), {enableHighAccuracy:true, timeout:10000, maximumAge:60000}); });
async function czOuvrir(id){
  try { CZA.m = (await czaapi("ma_mission", null, "id=" + id)).mission; } catch(e) { return toast(e.reseau ? "Pas de réseau." : e.message); }
  S.view = "czm"; czMis();
}
function czMis(){
  czaDir(); const m = CZA.m, fini = m.statut === "faite";
  const V = m.livret || {}, nOk = m.pieces.filter(p => p.ok).length, nP = m.pieces.length;
  const reste = nP - nOk, pc = nP ? Math.round(nOk / nP * 100) : 0;
  $("#view").innerHTML = `<div class="kt-head"><button class="kt-back" id="czb">${czt("retour")}</button><h2>${esc(m.logement)}</h2>
      <div class="t">${esc(czt(m.type))} · ${esc(czaD(m.date))}${m.heure ? " · " + esc(m.heure) : ""}</div>
      <div class="t">${esc(m.adresse)} ${esc(m.ville)}</div>
      ${V.repere ? `<div class="t"><b>${czt("repere")} :</b> ${esc(V.repere)}</div>` : ""}</div>
    <div class="kt-next kt-sum"><span class="mt"><b>${esc(m.heure || "–")}</b><span class="chip" style="--c:${CZ_TYPE_C[m.type] || "#5A5F5B"}">${esc(czt(fini ? "faite" : m.statut))}</span></span>
      ${nP ? `<span class="kt-prog"><i style="width:${pc}%"></i></span><span class="pgl">${nOk} ${czt("sur")} ${nP} ${czt("etapes")}</span>` : ""}
      ${V.carte && V.carte.maps ? `<span class="kt-nav"><a class="mp" href="${esc(V.carte.maps)}" target="_blank" rel="noopener">📍 ${czt("maps")}</a><a class="mp" href="${esc(V.carte.waze)}" target="_blank" rel="noopener">Waze</a></span>` : ""}</div>
    ${m.note || m.acces || m.kit_linge || (m.kit || []).length || V.code || (V.etapes || []).length ? `<div class="kt-card">
      ${m.note ? `<div class="kt-note"><b>${czt("consigne")}</b><div style="white-space:pre-line;margin-top:4px">${esc(m.note)}</div></div>` : ""}
      ${V.code ? `<div class="kt-code"><span>${czt("code")}</span><b>${esc(V.code)}</b>${V.serrure ? `<small>${esc(V.serrure)}</small>` : ""}</div>` : ""}
      ${m.acces ? `<details><summary><b>${czt("acces")}</b></summary><div style="white-space:pre-line">${esc(m.acces)}</div></details>` : ""}
      ${(V.etapes || []).length ? `<details><summary><b>${czt("chemin")}</b></summary>${V.etapes.map((e, i) => `<div style="margin-top:8px">${e.photo ? `<img src="${esc(e.photo)}" alt="" loading="lazy" style="width:100%;max-height:260px;object-fit:cover;display:block;border-radius:10px">` : ""}<div>${i + 1}. ${esc(e.texte)}</div></div>`).join("")}</details>` : ""}
      ${(m.kit || []).length ? `<div><b>${czt("linge")} :</b><div class="kt-kit">${m.kit.map(([n, q]) => `<span><b>${q}</b> ${esc(n)}</span>`).join("")}</div></div>` : m.kit_linge ? `<div><b>${czt("linge")} :</b> ${esc(m.kit_linge)}</div>` : ""}</div>` : ""}
    ${fini ? "" : m.commence ? "" : `<div class="kt-card"><button class="primary kt-big" id="czgo">${czt("jarrive")}</button><div class="hint">${czt("pos")}</div></div>`}
    <div class="kt-list">${m.pieces.map((p, i) => `<div class="kt-piece ${p.ok ? "ok" : ""}"><div class="kt-row nb">${p.ok ? `<span class="kt-ic ok">✓</span>` : `<span class="kt-ic wait"></span>`}
        <span class="tx"><b>${esc(p.piece)}</b><small${p.ok ? "" : ' class="todo"'}>${p.ok ? `${p.urls.length} ${czt("nphotos")}${fini ? "" : ` · <a href="#" class="kt-re" data-ok="${i}" data-v="0">${czt("rouvrir")}</a>`}` : `${czt("aprendre")} ${Math.max(0, p.photos - p.urls.length)} ${czt("nphotos")}${p.urls.length ? ` · ${p.urls.length}/${p.photos}` : ""}`}</small></span>
        ${p.urls.length ? `<span class="th"><img src="${esc(p.urls[0])}" alt=""></span>` : ""}</div>
      ${p.consignes ? `<div class="hint">${esc(p.consignes)}</div>` : ""}
      ${!p.ok && p.urls.length ? `<div class="kt-strip">${p.urls.map(u => `<img src="${esc(u)}" alt="">`).join("")}</div>` : ""}
      ${fini || p.ok ? "" : `<div class="kt-act"><label class="btn kt-photo">📷 ${czt("prendre")}<input type="file" accept="image/*" capture="environment" data-ph="${i}" hidden></label>
        <button class="${p.ok ? "btn ghost" : "btn kt-ok"}" data-ok="${i}" data-v="${p.ok ? 0 : 1}">${p.ok ? czt("rouvrir") : "✓ " + czt("valider")}</button></div>`}</div>`).join("")}</div>
    ${fini ? `<div class="kt-card kt-fini">${czt("merci")}</div>` : `<button class="kt-cta" id="czt"><span>${czt("terminer")}</span>${nP ? `<small>${reste ? reste + " " + czt("restent") : czt("tout")}</small>` : ""}</button>`}
    <button class="btn kt-pb" id="czs">⚠ ${czt("signaler")}</button>`;
  $("#czb").onclick = czHome;
  document.querySelectorAll("[data-ph]").forEach(inp => inp.onchange = async () => {
    const f = inp.files[0]; if (!f) return; const i = +inp.dataset.ph; const lab = inp.parentElement; lab.firstChild.textContent = czt("envoi");
    const blob = await shrink(f), fd = new FormData(); fd.append("id", m.id); fd.append("piece", i); fd.append("photo", blob, "photo.jpg");
    try { await czaapi("mission_photo", fd); }
    catch(e) { if (e.reseau) { await czQ.add({id:m.id, piece:i, blob}); toast(czt("attente")); } else toast(e.message); }
    czOuvrir(m.id);
  });
  document.querySelectorAll("[data-ok]").forEach(b => b.onclick = async ev => { ev.preventDefault(); try { await czaapi("mission_piece", {id:m.id, piece:+b.dataset.ok, ok:+b.dataset.v === 1}); czOuvrir(m.id); } catch(e) { toast(e.message); } });
  if ($("#czgo")) $("#czgo").onclick = async e => { e.target.disabled = true; const p = await czPos(); try { await czaapi("mission_debut", Object.assign({id:m.id}, p)); czOuvrir(m.id); } catch(x) { toast(x.message); e.target.disabled = false; } };
  if ($("#czt")) $("#czt").onclick = async () => { const p = await czPos(); try { const r = await czaapi("mission_terminer", Object.assign({id:m.id}, p)); if (r.ok === false) return toast(czt("manque") + " " + r.manques.join(", ")); toast(czt("merci")); czOuvrir(m.id); } catch(e) { toast(e.message); } };
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
