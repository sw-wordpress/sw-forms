/* SW Forms builder API v1: classic scripts can register before the SPA module starts. */
(function () {
  var pending = [];
  var runtime = null;
  function invoke(callback) {
    try { callback(runtime); } catch (error) { console.error("SW Forms add-on failed:", error); }
  }
  window.swFormsBuilder = {
    apiVersion: 1,
    register: function (callback) {
      if (runtime) invoke(callback);
      else pending.push(callback);
    },
    initialize: function (api) {
      if (runtime) return;
      runtime = api;
      pending.splice(0).forEach(invoke);
    }
  };
})();
