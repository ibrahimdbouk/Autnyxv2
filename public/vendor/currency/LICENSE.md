# Currency sign fonts

Single-glyph web fonts for currency signs too new for system fonts.

| File | Glyph | Source | Licence |
|---|---|---|---|
| `dirham.woff2` | U+20C3 UAE DIRHAM SIGN (Unicode 18.0) | npm `dirham` 1.5.3 (`dist/fonts/sans/dirham-sans.woff2`), © Pooya Golchian, https://github.com/pooyagolchian/dirham | MIT |
| `riyal-regular.woff2`, `riyal-bold.woff2` | U+20C1 SAUDI RIYAL SIGN (Unicode 17.0) | npm `riyal` 1.2.1 (`dist/fonts/`), © Pooya Golchian, https://github.com/pooyagolchian/riyal | MIT |

Used only through the `AxCurrency` @font-face rules in `App\Support\Branding::headAssets()`
(unicode-range limited to the single sign, so no other text changes font).

## MIT License

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and
associated documentation files (the "Software"), to deal in the Software without restriction,
including without limitation the rights to use, copy, modify, merge, publish, distribute,
sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or
substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT
NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES
OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
