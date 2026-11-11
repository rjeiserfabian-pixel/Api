document.addEventListener('DOMContentLoaded', () => {
    
    // ==========================================
    // UTILIDADES: TOAST NOTIFICATIONS
    // ==========================================
    const showToast = (type, title, message) => {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const iconSvg = type === 'success' 
            ? '<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none"><polyline points="20 6 9 17 4 12"></polyline></svg>'
            : '<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';

        toast.innerHTML = `
            <div class="toast-icon">${iconSvg}</div>
            <div class="toast-content">
                <h4>${title}</h4>
                <p>${message}</p>
            </div>
        `;
        
        container.appendChild(toast);
        
        // Trigger reflow to enable transition
        void toast.offsetWidth;
        toast.classList.add('show');
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400); // Wait for exit animation
        }, 4000);
    };

    // ==========================================
    // LÓGICA DE LOGIN & LOGOUT
    // ==========================================
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('btnLogin');
            const btnText = btn.querySelector('span');
            const loader = btn.querySelector('.loader');
            const errorDiv = document.getElementById('loginError');
            
            // UI State loading
            btn.disabled = true;
            btnText.classList.add('hidden');
            loader.classList.remove('hidden');
            errorDiv.classList.add('hidden');

            const formData = new FormData(loginForm);
            formData.append('action', 'login');

            try {
                const response = await fetch('upload_cert.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    showToast('success', 'Bienvenido', 'Acceso concedido.');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    throw new Error(data.message || 'Error de autenticación');
                }
            } catch (error) {
                errorDiv.textContent = error.message;
                errorDiv.classList.remove('hidden');
            } finally {
                btn.disabled = false;
                btnText.classList.remove('hidden');
                loader.classList.add('hidden');
            }
        });
    }

    const btnLogout = document.getElementById('btnLogout');
    if (btnLogout) {
        btnLogout.addEventListener('click', async () => {
            const formData = new FormData();
            formData.append('action', 'logout');
            await fetch('upload_cert.php', { method: 'POST', body: formData });
            window.location.reload();
        });
    }

    // ==========================================
    // TOGGLE PASSWORD VISIBILITY (Global)
    // ==========================================
    const setupPasswordToggle = (inputId, btnSelector) => {
        const input = document.getElementById(inputId);
        const btn = document.querySelector(btnSelector);
        if (btn && input) {
            btn.addEventListener('click', () => {
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                
                if (type === 'text') {
                    btn.innerHTML = '<svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
                } else {
                    btn.innerHTML = '<svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
                }
            });
        }
        return { input, btn };
    };

    // Initialize toggle for login if present
    setupPasswordToggle('password', '.toggle-pass-login');

    // ==========================================
    // LÓGICA DEL DASHBOARD (Si está logueado)
    // ==========================================
    const dashboardPanel = document.getElementById('dashboardPanel');
    if (!dashboardPanel) return; // Si no hay dashboard, detiene ejecución (está en login)

    // TABS
    let currentUploadType = 'pem'; // Default
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabPanes = document.querySelectorAll('.tab-pane');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            // Remove active classes
            tabBtns.forEach(b => b.classList.remove('active'));
            tabPanes.forEach(p => p.classList.remove('active'));
            
            // Add active class
            btn.classList.add('active');
            const target = btn.getAttribute('data-target');
            document.getElementById(target).classList.add('active');
            
            currentUploadType = target === 'tab-pem' ? 'pem' : 'pfx';
            validateForm();
        });
    });

    // DRAG AND DROP
    const initDropZone = (dzId, inputId, expectedExts) => {
        const dz = document.getElementById(dzId);
        const input = document.getElementById(inputId);
        if (!dz || !input) return;

        const updateUI = (file) => {
            if (file) {
                dz.classList.add('has-file');
                const p = dz.querySelector('p');
                p.textContent = file.name;
                p.classList.add('file-name');
            } else {
                dz.classList.remove('has-file');
                const p = dz.querySelector('p');
                p.textContent = 'Arrastra o haz click';
                p.classList.remove('file-name');
            }
            validateForm();
        };

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dz.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dz.addEventListener(eventName, () => dz.classList.add('dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dz.addEventListener(eventName, () => dz.classList.remove('dragover'), false);
        });

        dz.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const file = dt.files[0];
            
            if (file) {
                const ext = file.name.split('.').pop().toLowerCase();
                if (expectedExts.includes(ext)) {
                    // Update input files
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    input.files = dataTransfer.files;
                    updateUI(file);
                } else {
                    showToast('error', 'Archivo Inválido', `Se esperaba formato: ${expectedExts.join(', ')}`);
                }
            }
        });

        input.addEventListener('change', (e) => {
            updateUI(e.target.files[0]);
        });
    };

    initDropZone('dz-key', 'server_key_file', ['pem']);
    initDropZone('dz-cert', 'server_cert_file', ['pem']);
    initDropZone('dz-pfx', 'pfx_file', ['pfx', 'p12']);

    // Initialize toggle for PFX
    const pfxPassObj = setupPasswordToggle('pfx_password', '.toggle-pass');
    const pfxPassInput = pfxPassObj.input;
    
    // Show/Hide PFX password field based on file presence
    if (pfxPassInput) {
        document.getElementById('pfx_file').addEventListener('change', (e) => {
            const group = document.getElementById('pfxPassGroup');
            if (e.target.files.length > 0) {
                group.classList.remove('hidden');
                pfxPassInput.focus();
            } else {
                group.classList.add('hidden');
                pfxPassInput.value = '';
            }
        });
    }

    // ==========================================
    // VALIDACIÓN Y ENVÍO DEL FORMULARIO
    // ==========================================
    const btnUpload = document.getElementById('btnUpload');
    
    const validateForm = () => {
        let isValid = false;
        if (currentUploadType === 'pem') {
            const key = document.getElementById('server_key_file').files.length > 0;
            const cert = document.getElementById('server_cert_file').files.length > 0;
            isValid = key && cert;
        } else {
            const pfx = document.getElementById('pfx_file').files.length > 0;
            // No validamos la contraseña aquí estrictamente, puede ir vacía si el PFX no tiene contraseña, pero usualmente tiene.
            isValid = pfx; 
        }
        btnUpload.disabled = !isValid;
    };

    if (pfxPassInput) {
        pfxPassInput.addEventListener('input', validateForm);
    }

    btnUpload.addEventListener('click', async () => {
        const btnText = btnUpload.querySelector('span');
        const loader = btnUpload.querySelector('.loader');
        
        // UI State loading
        btnUpload.disabled = true;
        btnText.classList.add('hidden');
        loader.classList.remove('hidden');

        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('upload_type', currentUploadType);
        
        // Get selected environment
        const env = document.querySelector('input[name="environment"]:checked').value;
        formData.append('environment', env);

        if (currentUploadType === 'pem') {
            formData.append('server_key_file', document.getElementById('server_key_file').files[0]);
            formData.append('server_cert_file', document.getElementById('server_cert_file').files[0]);
        } else {
            formData.append('pfx_file', document.getElementById('pfx_file').files[0]);
            formData.append('pfx_password', pfxPassInput.value);
        }

        try {
            const response = await fetch('upload_cert.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                showToast('success', '¡Éxito!', data.message);
                
                // Reset form
                document.getElementById('uploadForm').reset();
                document.querySelectorAll('.drop-zone').forEach(dz => {
                    dz.classList.remove('has-file');
                    const p = dz.querySelector('p');
                    if(dz.id === 'dz-pfx') p.textContent = 'Arrastra tu archivo unificado aquí';
                    else p.textContent = 'Arrastra o haz click';
                    p.classList.remove('file-name');
                });
                document.getElementById('pfxPassGroup').classList.add('hidden');
                
            } else {
                throw new Error(data.message || 'Error desconocido al subir.');
            }
        } catch (error) {
            showToast('error', 'Error', error.message);
        } finally {
            validateForm(); // Re-validate to update button state
            btnText.classList.remove('hidden');
            loader.classList.add('hidden');
        }
    });
});
