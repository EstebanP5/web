'use strict';

(function () {
  function togglePassword() {
    var passwordInput = document.getElementById('password');
    var toggleIcon = document.getElementById('passwordToggleIcon');
    var toggleButton = document.querySelector('.password-toggle');

    if (!passwordInput || !toggleIcon || !toggleButton) {
      return;
    }

    if (passwordInput.type === 'password') {
      passwordInput.type = 'text';
      toggleIcon.classList.replace('fa-eye', 'fa-eye-slash');
      toggleButton.setAttribute('aria-label', 'Ocultar contraseña');
    } else {
      passwordInput.type = 'password';
      toggleIcon.classList.replace('fa-eye-slash', 'fa-eye');
      toggleButton.setAttribute('aria-label', 'Mostrar contraseña');
    }
  }

  window.togglePassword = togglePassword;

  document.addEventListener('DOMContentLoaded', function () {
    var doc = document;
    var form = doc.getElementById('loginForm');
    var loginButton = doc.getElementById('loginButton');
    var userInput = doc.getElementById('usuario');
    var passwordInput = doc.getElementById('password');
    var offlineBanner = doc.getElementById('loginOfflineBanner');
    var offlineText = doc.getElementById('loginOfflineText');
    var offlineBadge = doc.getElementById('loginOfflineBadge');
    var dynamicError = doc.getElementById('loginDynamicError');
    var dynamicErrorText = doc.getElementById('loginDynamicErrorText');
    var serverErrorAlert = doc.querySelector('[role="alert"].error-alert:not(#loginDynamicError)');
    var inputs = doc.querySelectorAll('.form-input');
    var asistencia = window.asistenciaPWA || null;
    var OFFLINE_ALLOWED_ROLES = ['responsable', 'servicio_especializado'];
    var storedCredentialsCount = 0;
    var scheduleIdle = window.requestIdleCallback || function (cb) { return setTimeout(cb, 120); };

    if (userInput && !userInput.value) {
      requestAnimationFrame(function () {
        userInput.focus();
      });
    }

    if (asistencia && typeof asistencia.init === 'function') {
      asistencia.init();
    }

    updateStatusBanner();
    scheduleIdle(refreshStoredCredentials);

    window.addEventListener('online', updateStatusBanner);
    window.addEventListener('offline', updateStatusBanner);

    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        hideError();

        var usuario = (userInput.value || '').trim();
        var password = passwordInput.value || '';

        if (!usuario || !password) {
          showError('Ingresa tu usuario y contraseña.');
          return;
        }

        if (!navigator.onLine) {
          handleOfflineLogin(usuario, password);
        } else {
          handleOnlineLogin(usuario, password);
        }
      });
    }

    if (serverErrorAlert) {
      setTimeout(function () {
        serverErrorAlert.style.opacity = '0';
        serverErrorAlert.style.transform = 'translateY(-10px)';
        setTimeout(function () {
          if (serverErrorAlert.parentNode) {
            serverErrorAlert.parentNode.removeChild(serverErrorAlert);
          }
        }, 320);
      }, 6000);
    }

    inputs.forEach(function (input) {
      input.addEventListener('focus', function () {
        this.parentElement.classList.add('focused');
      });

      input.addEventListener('blur', function () {
        this.parentElement.classList.remove('focused');
      });
    });

    function isRoleAllowed(rol) {
      if (!rol) return false;
      return OFFLINE_ALLOWED_ROLES.indexOf(String(rol).toLowerCase()) !== -1;
    }

    function handleOnlineLogin(usuario, password) {
      setLoading(true);
      var formData = new FormData(form);
      formData.append('ajax', '1');

      fetch('login.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'include'
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.success) {
            var message = data && data.error ? data.error : 'No se pudo iniciar sesión.';
            throw new Error(message);
          }
          return data;
        });
      }).then(function (data) {
        if (isRoleAllowed(data.rol) && asistencia && asistencia.auth && typeof asistencia.auth.saveCredential === 'function') {
          return asistencia.auth.saveCredential({
            username: usuario,
            password: password,
            rol: data.rol || '',
            redirect: data.redirect || 'responsable/dashboard.php',
            nombre: data.user && data.user.name ? data.user.name : ''
          }).catch(function (error) {
            console.warn('No se pudo guardar la credencial offline', error);
          }).then(function () {
            return data;
          });
        }
        return data;
      }).then(function (data) {
        refreshStoredCredentials();
        window.location.href = data.redirect || 'responsable/dashboard.php';
      }).catch(function (error) {
        console.error('Error login en línea', error);
        showError(error.message || 'No se pudo iniciar sesión.');
        setLoading(false);
      });
    }

    function handleOfflineLogin(usuario, password) {
      setLoading(true);
      if (!asistencia || !asistencia.auth || typeof asistencia.auth.validateCredential !== 'function') {
        showError('Este dispositivo no tiene credenciales guardadas.');
        setLoading(false);
        return;
      }

      asistencia.auth.validateCredential(usuario, password).then(function (record) {
        if (!record || !isRoleAllowed(record.rol)) {
          throw new Error('Las credenciales no coinciden con un registro guardado.');
        }

        if (offlineText) {
          offlineText.textContent = '🔐 Credenciales verificadas sin conexión. Abriendo panel…';
        }

        setTimeout(function () {
          window.location.href = record.redirect || 'responsable/dashboard.php';
        }, 450);
      }).catch(function (error) {
        console.warn('Error login offline', error);
        showError(error.message || 'No se pudo validar sin conexión.');
        setLoading(false);
      });
    }

    function setLoading(isLoading) {
      if (!loginButton) return;
      if (isLoading) {
        loginButton.classList.add('loading');
        loginButton.disabled = true;
      } else {
        loginButton.classList.remove('loading');
        loginButton.disabled = false;
      }
    }

    function showError(message) {
      if (!dynamicError || !dynamicErrorText) return;
      dynamicErrorText.textContent = message;
      dynamicError.style.display = 'flex';
    }

    function hideError() {
      if (!dynamicError || !dynamicErrorText) return;
      dynamicErrorText.textContent = '';
      dynamicError.style.display = 'none';
    }

    function refreshStoredCredentials() {
      if (!asistencia || !asistencia.auth || typeof asistencia.auth.getAllCredentials !== 'function') {
        storedCredentialsCount = 0;
        updateStatusBanner();
        return;
      }

      asistencia.auth.getAllCredentials().then(function (credentials) {
        if (!Array.isArray(credentials)) {
          storedCredentialsCount = 0;
          updateStatusBanner();
          return;
        }

        var allowed = [];
        credentials.forEach(function (record) {
          if (record && isRoleAllowed(record.rol)) {
            allowed.push(record);
          } else if (record && record.username && asistencia.auth && typeof asistencia.auth.removeCredential === 'function') {
            asistencia.auth.removeCredential(record.username).catch(function (cleanupError) {
              console.warn('No se pudo limpiar credencial no permitida', cleanupError);
            });
          }
        });

        storedCredentialsCount = allowed.length;

        if (storedCredentialsCount > 0 && userInput && !userInput.value) {
          var recent = null;
          allowed.forEach(function (record) {
            if (!recent || (record.updatedAt || 0) > (recent.updatedAt || 0)) {
              recent = record;
            }
          });
          if (recent && recent.username) {
            userInput.value = recent.username;
          }
        }
      }).catch(function (error) {
        console.warn('No se pudo leer credenciales guardadas', error);
        storedCredentialsCount = 0;
      }).finally(function () {
        updateStatusBanner();
      });
    }

    function updateStatusBanner() {
      if (!offlineBanner || !offlineText) return;

      var online = navigator.onLine;
      var shouldShow = !online;
      offlineBanner.style.display = shouldShow ? 'flex' : 'none';
      offlineBanner.classList.toggle('online', false);

      if (!online) {
        offlineText.textContent = storedCredentialsCount > 0
          ? '📡 Sin conexión. Puedes iniciar sesión con las credenciales guardadas.'
          : '📡 Sin conexión. Inicia sesión en línea una vez para activar el modo offline.';
      }

      if (!offlineBadge) {
        return;
      }

      if (!online && storedCredentialsCount > 0) {
        offlineBadge.style.display = 'inline-flex';
        offlineBadge.textContent = storedCredentialsCount + ' guardada' + (storedCredentialsCount !== 1 ? 's' : '');
      } else {
        offlineBadge.style.display = 'none';
      }
    }
  });
})();
