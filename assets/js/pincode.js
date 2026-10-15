/* Typing a 6-digit pin code fills City and State in the same form.
   Fails silently: offline or an unknown pin just leaves both fields editable.
   Same code is appended to admin/assets/admin.js (admin pages cannot reach /assets). */
(function () {
  'use strict';

  var seq = 0;

  function set(el, value) {
    if (!el || !value) return;
    if (el.tagName === 'SELECT') {
      var want = value.toLowerCase();
      var opt = Array.prototype.find.call(el.options, function (o) {
        return o.value.toLowerCase() === want || o.text.toLowerCase() === want;
      });
      if (!opt) return;
      value = opt.value;
    }
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  document.addEventListener('input', function (e) {
    var pin = e.target;
    if (!pin || pin.name !== 'pin_code' || !pin.form) return;
    var digits = pin.value.trim();
    if (!/^[1-9][0-9]{5}$/.test(digits)) return;

    var mine = ++seq;
    fetch('https://api.postalpincode.in/pincode/' + digits)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var office = data && data[0] && data[0].Status === 'Success' && data[0].PostOffice && data[0].PostOffice[0];
        if (!office || mine !== seq || pin.value.trim() !== digits) return;
        set(pin.form.elements.city, office.District);
        set(pin.form.elements.state, office.State);
      })
      .catch(function () {});
  });
})();
