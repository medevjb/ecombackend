const search = document.querySelector("#search");
const method = document.querySelector("#method");
const resultCount = document.querySelector("#result-count");
const endpoints = [...document.querySelectorAll("[data-method]")];

function filterEndpoints() {
  const query = search.value.trim().toLowerCase();
  let visible = 0;
  for (const endpoint of endpoints) {
    const matches = (!method.value || endpoint.dataset.method === method.value)
      && endpoint.querySelector("summary").textContent.toLowerCase().includes(query);
    endpoint.hidden = !matches;
    if (matches) visible++;
  }
  resultCount.textContent = visible + (visible === 1 ? " endpoint" : " endpoints");
}

search.addEventListener("input", filterEndpoints);
method.addEventListener("change", filterEndpoints);
filterEndpoints();
