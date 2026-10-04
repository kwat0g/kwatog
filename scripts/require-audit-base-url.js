const base = process.env.BASE_URL;

if (!base) throw new Error('BASE_URL must point to an isolated test application.');

module.exports = base.replace(/\/$/, '');
