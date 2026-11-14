import zlib,sys
def pdf(objs):
    out=b"%PDF-1.7\n%\xe2\xe3\xcf\xd3\n";offs=[]
    for i,o in enumerate(objs,1):
        offs.append(len(out)); out+=b"%d 0 obj\n"%i+o+b"\nendobj\n"
    x=len(out); out+=b"xref\n0 %d\n0000000000 65535 f \n"%(len(objs)+1)+b"".join(b"%010d 00000 n \n"%o for o in offs)
    out+=b"trailer\n<</Size %d/Root 1 0 R>>\nstartxref\n%d\n%%%%EOF\n"%(len(objs)+1,x); return out
base=[b"<</Type/Catalog/Pages 2 0 R>>",b"<</Type/Pages/Kids[3 0 R]/Count 1>>",b"<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R>>"]
def stream(d,extra=b""): return b"<</Length %d%s>>stream\n"%(len(d),extra)+d+b"\nendstream"
# 1 normal with image-like binary containing the bytes "/JS " by chance
img=bytes(range(256))*10+b"\x9a/JS \x11"+bytes(range(256))
open("fp.pdf","wb").write(pdf(base+[stream(img,b"/Filter/DCTDecode")]))
# 2 clean
open("clean.pdf","wb").write(pdf(base+[stream(zlib.compress(b"BT /F1 12 Tf (Hello) Tj ET"),b"/Filter/FlateDecode")]))
# 3 bypass: OpenAction JS inside compressed object stream
inner=b"5 0 <</S/JavaScript/JS(app.alert(1))>>"
hdr=b"5 0 "; objstm=zlib.compress(b"<</S/JavaScript/JS(app.alert(1))>>")
cat=b"<</Type/Catalog/Pages 2 0 R/OpenAction 5 0 R>>"
open("objstm.pdf","wb").write(pdf([cat]+base[1:]+[stream(b""),stream(zlib.compress(b"5 0 <</S/JavaScript/JS(app.alert(1))>>"),b"/Type/ObjStm/N 1/First 4/Filter/FlateDecode")]))
# 4 hex escape
open("hex.pdf","wb").write(pdf([b"<</Type/Catalog/Pages 2 0 R/OpenAction<</S/Java#53cript/J#53(x)>>>>"]+base[1:]+[stream(b"")]))
# 5 /URI action (not filtered by design), /SubmitForm, /GoToR, /AA
open("uri.pdf","wb").write(pdf([b"<</Type/Catalog/Pages 2 0 R/OpenAction<</S/URI/URI(http://evil.example)>>/AcroForm<</XFA 6 0 R>>>>"]+base[1:]+[stream(b"")]))
