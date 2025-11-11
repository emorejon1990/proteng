let deferredPrompt;
const installBtn = document.createElement('button');
installBtn.textContent = '📱 Install Proteng-Lab App';
installBtn.style.display = 'none';
installBtn.style.position = 'fixed';
installBtn.style.bottom = '20px';
installBtn.style.right = '20px';
installBtn.style.padding = '12px 20px';
installBtn.style.background = '#d97706';
installBtn.style.color = '#fff';
installBtn.style.border = 'none';
installBtn.style.borderRadius = '8px';
installBtn.style.cursor = 'pointer';
installBtn.style.boxShadow = '0 4px 10px rgba(0,0,0,0.2)';
document.body.appendChild(installBtn);

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  installBtn.style.display = 'block';

  installBtn.addEventListener('click', () => {
    installBtn.style.display = 'none';
    deferredPrompt.prompt();
    deferredPrompt.userChoice.then((choiceResult) => {
      if (choiceResult.outcome === 'accepted') {
        console.log('✅ Usuario instaló la app');
      } else {
        console.log('❌ Usuario canceló instalación');
      }
      deferredPrompt = null;
    });
  });
});
