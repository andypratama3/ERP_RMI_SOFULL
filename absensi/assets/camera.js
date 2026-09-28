
async function initCamera(videoEl){
  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" }, audio: false });
  videoEl.srcObject = stream;
  await videoEl.play();
  return stream;
}
function captureToDataURL(videoEl, canvasEl){
  const w = videoEl.videoWidth || 640;
  const h = videoEl.videoHeight || 480;
  canvasEl.width = w; canvasEl.height = h;
  const ctx = canvasEl.getContext("2d");
  ctx.drawImage(videoEl, 0, 0, w, h);
  return canvasEl.toDataURL("image/jpeg", 0.85);
}
function getGeo(){
  return new Promise((resolve)=>{
    if(!navigator.geolocation) return resolve(null);
    navigator.geolocation.getCurrentPosition(
      (pos)=>resolve({lat: pos.coords.latitude, lng: pos.coords.longitude, acc: Math.round(pos.coords.accuracy||0)}),
      ()=>resolve(null),
      { enableHighAccuracy:true, timeout:8000, maximumAge:0 }
    );
  });
}
