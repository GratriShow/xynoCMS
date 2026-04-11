const fs = require('node:fs');
const crypto = require('node:crypto');

async function sha1File(filePath) {
  return await new Promise((resolve, reject) => {
    const hash = crypto.createHash('sha1');
    const stream = fs.createReadStream(filePath);
    stream.on('data', (d) => hash.update(d));
    stream.on('error', reject);
    stream.on('end', () => resolve(hash.digest('hex')));
  });
}

module.exports = {
  sha1File,
};
