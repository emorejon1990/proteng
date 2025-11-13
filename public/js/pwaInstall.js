// let deferredPrompt;
// const installBtn = document.createElement('button');
// installBtn.textContent = '📱 Install Proteng-Lab App';
// installBtn.style.display = 'none';
// installBtn.style.position = 'fixed';
// installBtn.style.bottom = '20px';
// installBtn.style.right = '20px';
// installBtn.style.padding = '12px 20px';
// installBtn.style.background = '#d97706';
// installBtn.style.color = '#fff';
// installBtn.style.border = 'none';
// installBtn.style.borderRadius = '8px';
// installBtn.style.cursor = 'pointer';
// installBtn.style.boxShadow = '0 4px 10px rgba(0,0,0,0.2)';
// document.body.appendChild(installBtn);

// window.addEventListener('beforeinstallprompt', (e) => {
//   e.preventDefault();
//   deferredPrompt = e;
//   installBtn.style.display = 'block';

//   installBtn.addEventListener('click', () => {
//     installBtn.style.display = 'none';
//     deferredPrompt.prompt();
//     deferredPrompt.userChoice.then((choiceResult) => {
//       if (choiceResult.outcome === 'accepted') {
//         console.log('✅ Usuario instaló la app');
//       } else {
//         console.log('❌ Usuario canceló instalación');
//       }
//       deferredPrompt = null;
//     });
//   });
// });
let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
    // Evita que Chrome muestre el prompt automáticamente
    e.preventDefault();
    deferredPrompt = e;

    // Muestra el botón de instalación
    const installButton = document.getElementById('installButton');
    installButton.style.display = 'block';

    installButton.addEventListener('click', async () => {
        // Si es Android → lanza el prompt real
        deferredPrompt.prompt();
        const choiceResult = await deferredPrompt.userChoice;

        if (choiceResult.outcome === 'accepted') {
            console.log('El usuario aceptó instalar la app');
        } else {
            console.log('El usuario canceló la instalación');
        }

        deferredPrompt = null;
    });
});

// Detectar si es iOS
document.addEventListener('DOMContentLoaded', () => {
    const isIos = /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());
    const isInStandalone = ('standalone' in window.navigator) && window.navigator.standalone;
    const installButton = document.getElementById('installButton');
    const iosModal = document.getElementById('iosModal');
    const closeIosModal = document.getElementById('closeIosModal');

    // Si es iOS y no está instalada → mostrar el botón
    if (isIos && !isInStandalone) {
        installButton.style.display = 'block';
        installButton.addEventListener('click', () => {
            iosModal.classList.remove('hidden');
        });

        closeIosModal.addEventListener('click', () => {
            iosModal.classList.add('hidden');
        });
    }
});
