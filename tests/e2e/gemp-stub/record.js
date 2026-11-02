// Re-records the format answers the Gemp stub serves, from a real Gemp.
//   GEMP_URL=https://test.lotrtcgpc.net/gemp-lotr-server node record.js
// The errata map (recorded/errata-map.json) comes from Red Book's card data:
//   docker exec <db> mariadb ... "select b.code, c.code from card c join card b on b.id=c.errata_of_id"
const fs = require('fs');
const path = require('path');
const base = (process.env.GEMP_URL || 'https://test.lotrtcgpc.net/gemp-lotr-server').replace(/\/$/, '');
const save = (name, data) => fs.writeFileSync(path.join(__dirname, 'recorded', name), JSON.stringify(data));
(async () => {
  save('deck-formats.json', await (await fetch(`${base}/deck/formats`, { method: 'POST' })).json());
  save('hall-formats.json', await (await fetch(`${base}/hall/formats/json`)).json());
  console.log('recorded from', base);
})();
