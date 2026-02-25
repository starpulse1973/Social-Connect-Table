# 구현 계획 v1.0.3

## 1. 셀별 스타일링 (text-align, vertical-align, 텍스트 컬러, 배경색)

### 데이터 구조
`options.cell_styles` 에 저장 (DB 스키마 변경 없음):
```json
{
  "A1": { "ta": "center", "va": "middle", "color": "#ff0000", "bg": "#ffff00" },
  "B3": { "ta": "right", "bg": "#e0e0e0" }
}
```
- `ta` = text-align (left / center / right)
- `va` = vertical-align (top / middle / bottom)
- `color` = 텍스트 색상
- `bg` = 배경색

### 수정 파일 (6개)

| # | 파일 | 변경 내용 |
|---|------|-----------|
| 1 | `class-advt-table-model.php` | `default_options()`에 `cell_styles` 추가 |
| 2 | `class-advt-rest-api.php` | `sanitize_options()`에 `cell_styles` 화이트리스트 추가 |
| 3 | `admin-editor.js` | (a) cellStyles 상태 객체 관리, (b) 컨텍스트 메뉴에 Cell Formatting 서브메뉴 추가 (Text Align → Left/Center/Right, Vertical Align → Top/Middle/Bottom, Text Color, Background Color), (c) 색상 선택은 `<input type="color">` 팝업, (d) jspreadsheet `setStyle()` API로 에디터 내 시각 반영, (e) `_gatherOptions()`에 cellStyles 포함, (f) 초기 로드 시 저장된 스타일 복원 |
| 4 | `class-advt-frontend.php` | `build_table_html()`에서 cell_styles → 인라인 style 속성으로 렌더링 |
| 5 | `admin-editor.css` | 색상 피커 팝업 스타일 |
| 6 | `advanced-wp-tables.php` | 버전 → 1.0.3 |

### 컨텍스트 메뉴 구조
우클릭 메뉴 끝에 구분선 후:
```
─────────────
Text Align     ▸  Left | Center | Right
Vertical Align ▸  Top | Middle | Bottom
Text Color...       (팝업으로 color picker)
Background Color... (팝업으로 color picker)
Clear Formatting    (해당 셀 스타일 전부 제거)
```

### jspreadsheet 에디터 내 시각 반영
- `spreadsheet.setStyle(cellName, 'text-align', value)` 등으로 에디터 격자에도 실시간 반영
- 저장된 스타일은 초기화 시 `spreadsheet.setStyle()` 루프로 복원

## 2. 폴더명 변경 + 버전 변경

| 항목 | 변경 |
|------|------|
| 플러그인 폴더명 | `advanced-wp-tables` → `social-connect-table` |
| 버전 | 1.0.1 → 1.0.3 |
| ADVT_VERSION | 1.0.3 |
| Plugin header Version | 1.0.3 |

내부 식별자(ADVT_ 상수, advt- 클래스, REST namespace 등)는 유지.

## 3. ZIP 생성
- `social-connect-table-1.0.3.zip` (루트: `social-connect-table/`)
- PowerShell `Compress-Archive` 사용
