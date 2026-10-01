/**
 * Contratos de la fuente de bingo: qué es un item y qué es un bingo cargable.
 *
 * Solo JSDoc y constantes, sin lógica. `apiBingo.js` (el backoffice) produce el `BingoConfig`
 * que consume el juego.
 */

/** @typedef {'numeric'|'music'|'image'} ModeKind */

/**
 * @typedef {Object} BingoItem
 * @property {string} id            // estable: 'i<itemId>'
 * @property {'music'|'image'} kind
 * @property {string} label         // lo que sale en el cartón y en la tele (antes titulo/texto)
 * @property {string|null} sublabel // artista; null en fotos
 * @property {{type:'youtube'|'image', url:string|null, thumbUrl:string|null,
 *             videoId:string|null, startSeconds:number|null, endSeconds:number|null}} media
 */

/**
 * @typedef {Object} BingoConfig
 * @property {string} id            // '<bingoId>'
 * @property {string} title
 * @property {{ numeric: {enabled:boolean, label:string},
 *              music:   {enabled:boolean, label:string, card:[number,number], items:BingoItem[]},
 *              image:   {enabled:boolean, label:string, card:[number,number], items:BingoItem[]} }} modes
 * @property {{leadIn:number, minGap:number, spreadOver:number}} plan
 */

// Modos de juego, en el orden en que se presentan. `numeric` es la base; los otros dos, sorpresas.
export const MODE_KINDS = ['numeric', 'music', 'image'];
