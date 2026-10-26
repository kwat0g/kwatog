const BLACK_SCRIM = /\bbg-black\/\d{1,3}\b/;
// Product-photo eye icon needs white ink over its fixed black contrast scrim in every theme.
const WHITE_PHOTO_ICON = /\bbg-black\/60\s+text-white\b/;

export function isAllowedNeutral(match, line) {
  return BLACK_SCRIM.test(match) || (match === 'text-white' && WHITE_PHOTO_ICON.test(line));
}
