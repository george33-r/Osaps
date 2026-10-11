/**
 * Photographs and video supplied by the OSAPS owner, who confirmed on 11 Oct 2026 that they have
 * permission from the photographers and the people shown. Credits follow the marks visible on
 * each image; where none is visible the credit says the image is used with permission.
 * Captions describe what is visible and never claim the person endorses OSAPS.
 */
export interface Photo {
  src: string
  alt: string
  credit: string
  culture: 'Yorùbá' | 'Igbo' | 'Hausa' | 'Ankara' | 'Niger Delta' | 'Nigerian'
  look: string
  garment: string
  occasion: string
  fabricCats: string[]
}

const m = (file: string) => `${import.meta.env.BASE_URL}media/${file}`
const PERMISSION = 'Used with permission'

export const HERO_VIDEO = {
  src: m('hero-wedding-dance.mp4'),
  webm: m('hero-wedding-dance.webm'),
  poster: m('hero-wedding-dance-poster.jpg'),
  caption: 'A couple dances in matching wrappers as guests spray money at a traditional wedding.',
  credit: PERMISSION,
}

export const P = {
  orangeGele: { src: m('yoruba-iro-buba-gele-orange.webp'), alt: 'A woman in an orange bùbá, a brown striped aṣọ-òkè ìró and ìpèlé, a matching gèlè and coral beads', credit: PERMISSION, culture: 'Yorùbá', look: 'Ìró, bùbá and gèlè in aṣọ-òkè', garment: 'iro_buba', occasion: 'family_event', fabricCats: ['aso_oke'] },
  burgundyAgbada: { src: m('yoruba-agbada-burgundy-coral.webp'), alt: 'A man in a burgundy striped agbada with embroidered chest, white sòkòtò, a matching fila and coral beads', credit: PERMISSION, culture: 'Yorùbá', look: 'Agbada with fila and coral', garment: 'agbada', occasion: 'traditional_wedding', fabricCats: ['aso_oke', 'damask'] },
  igboMan: { src: m('igbo-man-red-cap-coral.webp'), alt: 'A man in a black and gold embroidered top, a red cap and red coral beads', credit: 'Kairos', culture: 'Igbo', look: 'Embroidered top, red cap and coral', garment: 'senator', occasion: 'traditional_wedding', fabricCats: ['damask', 'george'] },
  teal: { src: m('arewa-groomsmen-teal-kaftan.webp'), alt: 'Groomsmen walking in matching teal kaftans and embroidered hula caps', credit: PERMISSION, culture: 'Hausa', look: 'Matching kaftans and hula caps', garment: 'kaftan', occasion: 'own_wedding', fabricCats: ['senator', 'damask'] },
  blueRiga: { src: m('arewa-blue-riga-rawani.webp'), alt: 'A seated man in a royal blue embroidered babban riga with a white rawani turban', credit: PERMISSION, culture: 'Hausa', look: 'Babban riga and rawani', garment: 'agbada', occasion: 'festival', fabricCats: ['damask', 'senator'] },
  durbar: { src: m('durbar-emir-horseback.webp'), alt: 'An emir on a decorated horse under a ceremonial umbrella, surrounded by attendants at a Durbar', credit: PERMISSION, culture: 'Hausa', look: 'Durbar regalia', garment: 'agbada', occasion: 'festival', fabricCats: ['damask'] },
  sequinGele: { src: m('owambe-sequin-gele.webp'), alt: 'A smiling woman in a bronze gèlè and a dark outfit covered in large copper sequins', credit: PERMISSION, culture: 'Yorùbá', look: 'Owambe sequins and gèlè', garment: 'iro_buba', occasion: 'party', fabricCats: ['lace', 'aso_oke'] },
  asoOkeGele: { src: m('aso-oke-gele-coral.webp'), alt: 'A woman in a tan top, a striped gèlè and coral beads, holding up a length of striped aṣọ-òkè', credit: PERMISSION, culture: 'Yorùbá', look: 'Aṣọ-òkè, straight from the loom', garment: 'iro_buba', occasion: 'family_event', fabricCats: ['aso_oke'] },
  adireHalter: { src: m('adire-blue-halter-dress.webp'), alt: 'A woman in a blue and black halter dress with a silver panel down the front', credit: PERMISSION, culture: 'Ankara', look: 'Halter dress with a centre panel', garment: 'gown', occasion: 'party', fabricCats: ['adire', 'ankara'] },
  halterGowns: { src: m('ankara-halter-gowns.webp'), alt: 'Two women in long purple and red print halter gowns with pockets', credit: PERMISSION, culture: 'Ankara', look: 'Long halter gowns', garment: 'gown', occasion: 'wedding_guest', fabricCats: ['ankara'] },
  greenShirt: { src: m('ankara-green-shirt-dress.webp'), alt: 'A woman in a green print shirt dress with puff sleeves', credit: PERMISSION, culture: 'Ankara', look: 'Shirt dress with puff sleeves', garment: 'gown', occasion: 'work', fabricCats: ['ankara'] },
  gownsDuo: { src: m('ankara-gowns-duo.webp'), alt: 'Two women in fitted print gowns, one black and white, one orange and white', credit: 'total9ja', culture: 'Ankara', look: 'Fitted gowns with puff sleeves', garment: 'gown', occasion: 'wedding_guest', fabricCats: ['ankara'] },
  brownAgbada: { src: m('yoruba-agbada-fila-group.webp'), alt: 'A man in a brown agbada with an embroidered chest and a cream striped fila, among men dressed alike', credit: 'G. V. Utong', culture: 'Yorùbá', look: 'Agbada and fila, aṣọ-ẹbí style', garment: 'agbada', occasion: 'family_event', fabricCats: ['aso_oke', 'damask'] },
  groomsmen: { src: m('yoruba-groom-groomsmen-agbada.webp'), alt: 'A groom in an embroidered bronze agbada with coral beads and a staff, with groomsmen in cream agbada and caps', credit: PERMISSION, culture: 'Yorùbá', look: 'The groom and his men', garment: 'agbada', occasion: 'own_wedding', fabricCats: ['damask', 'aso_oke'] },
  asoOkeCouple: { src: m('yoruba-couple-aso-oke-agbada.webp'), alt: 'A couple in matching brown striped aṣọ-òkè, she in a gèlè and coral, he in agbada and fila', credit: PERMISSION, culture: 'Yorùbá', look: 'Matching aṣọ-òkè for the couple', garment: 'agbada', occasion: 'own_wedding', fabricCats: ['aso_oke'] },
  igboBride: { src: m('igbo-bride-coral-dance.webp'), alt: 'A bride in red and white with layers of coral beads and a coral headpiece, dancing among guests', credit: PERMISSION, culture: 'Igbo', look: 'Coral and red for the bride', garment: 'bridal', occasion: 'own_wedding', fabricCats: ['lace', 'george'] },
  igboCouple: { src: m('igbo-wedding-couple.webp'), alt: 'A bride in a wine velvet gown trimmed with coral beads beside a groom in a red cap, gold and black outfit and coral', credit: 'OmaStyle Bride', culture: 'Igbo', look: 'Velvet, coral and gold', garment: 'bridal', occasion: 'own_wedding', fabricCats: ['lace', 'george'] },
  whiteCoral: { src: m('white-coral-wedding-couple.webp'), alt: 'A couple in white with long coral bead necklaces, he in a black hat with a cane, she in a white gèlè with a fan', credit: PERMISSION, culture: 'Niger Delta', look: 'White and coral', garment: 'bridal', occasion: 'own_wedding', fabricCats: ['lace'] },
} satisfies Record<string, Photo>

/** The lookbook, in the order it is shown. */
export const LOOKBOOK: Photo[] = [P.orangeGele, P.burgundyAgbada, P.gownsDuo, P.igboMan, P.sequinGele, P.teal, P.adireHalter, P.brownAgbada, P.asoOkeGele, P.halterGowns, P.blueRiga, P.greenShirt]
