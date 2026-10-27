// Adds a Copy button to every code block.
document.querySelectorAll('pre').forEach(function (pre) {
  var btn = document.createElement('button');
  btn.className = 'copy';
  btn.type = 'button';
  btn.textContent = 'Copy';
  btn.addEventListener('click', function () {
    var text = pre.querySelector('code') ? pre.querySelector('code').innerText : pre.innerText;
    navigator.clipboard.writeText(text.trim()).then(function () {
      btn.textContent = 'Copied';
      setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
    });
  });
  pre.appendChild(btn);
});

// On small screens the table of contents starts folded so the guide comes first.
var toc = document.querySelector('details.toc');
if (toc && window.matchMedia('(max-width: 900px)').matches) toc.open = false;
